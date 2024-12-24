<?php

namespace App\Cron;

require_once(BASE_PATH . '/assets/includes/db.php');
require_once(BASE_PATH . '/assets/includes/task.php');
require_once(BASE_PATH . '/assets/includes/purchase.php');
require_once(BASE_PATH . '/assets/includes/constants.php');

abstract class GetDataBase
{
    const NO_PROVIDER = 'noprovider';
    const NO_PROCEDURE = 'noprocedure';
    const PROVIDER_PATH = "/assets/includes/%placeholder%.php";
    const PROCEDURE_PATH = "/assets/includes/purchprocess/%placeholder%.php";
    const PROVIDER_STATUS_PROCESSING = 'processing';
    const PROVIDER_STATUS_SUCCESS = 'success';
    const PROVIDER_STATUS_ERROR = 'error';

    protected static $flow = [
        'Purchases' => 'getPurchases',
        'PurchasesDocs' => 'getDocs',
        'updPurchasesList' => 'getTaskUpdPurchasesList'
    ];

    protected \Task $task;
    protected \Db $db;
    protected string $providerClass;
    protected string $idProc;
    protected string $detailedLogging;

    public function __construct()
    {
        $this->db = new \Db();
        set_time_limit(1200);
    }

    /**
     * Sets up local environment
     *
     * @return $this
     */
    public function setUpEnvironment($commType = 0): self
    {
        $argv = $_SERVER['argv'];
        $this->idProc = (!isset($argv[1])) ? 1 : intval($argv[1]);
        // set to 1 if there is a need for a detailed log
        $this->detailedLogging = (!isset($argv[2])) ? 0 : intval($argv[2]);

        if (isset($_GET['id_proc'])) {
            $this->idProc = intval($_GET['id_proc']);
        }

        $this->task = new \Task($this->idProc, $commType);
        $this->checkRequiredAssets(
            'provider',
            BASE_PATH . self::PROVIDER_PATH
        );
        $this->checkRequiredAssets(
            'zprocedure',
            BASE_PATH . self::PROCEDURE_PATH,
            $this->task->getSuffix($this->task->task['ztype'])
        );

        $this->providerClass = $this->task->task['provider'];

        if ($this->task->task["status"] == 1) {
            $this->task->setStatus(2);
        }

        return $this;
    }

    /**
     * Loads necessary assets
     *
     * @param $key
     * @param $path
     * @param $suffix
     * @return void
     */
    protected function checkRequiredAssets($key, $path, $suffix = ''): void
    {
        $nameLowercase = strtolower($this->task->task[$key]);
        $path = str_replace('%placeholder%', $nameLowercase . $suffix, $path);

        $against = (empty($suffix)) ? self::NO_PROVIDER : self::NO_PROCEDURE;
        if ($this->task->task[$key] != $against) {
            if (!file_exists($path)) {
                $this->task->logAction("Module {$this->task->task[$key]} not found!");
                $this->db->close();

                die();
            }

            require_once($path);
        }
    }

    /**
     * Writes a particular message into a log
     *
     * @param $message
     * @param $data
     * @return void
     */
    protected function setLogMarker($message, $data = ''): void
    {
        if (!(0b1 & $this->detailedLogging)) {
            return;
        }

        if (!empty($data) || is_array($data)) {
            ob_start();
            var_dump($data);
            $data = ob_get_contents();
            ob_get_clean();
        }

        $this->task->logAction($message . $data);
    }

    protected function callProvider(&$params, &$provider): array
    {
        $filter = \Purchases::getFilter($params);

        try {
            $purchases = $provider->getPurchases($filter);
        } catch (\Exception $e) {
            $this->setLogMarker("An exception code during the Provider Call is - {$e->getCode()}");
            $this->setLogMarker("An exception during the Provider Call is - {$e->getMessage()}");

            die('We stop execution because we need to see why we could\'t succeed with Provider Call!');
        }

        $this->setLogMarker(
            "A checkpoint is after getting purchases by API. Here we check a provider `status` on error"
        );

        if ($provider->status == 'error') {
            $this->task->saveData(['skip' => $params['skip'], 'status' => 4]);
            $params['provider'] = $provider;
            $this->task->logAction(\Purchases::providerErrorMessage($purchases, $params));
            $this->db->close();

            die();
        }

        $this->setLogMarker("A checkpoint is before we check a provider `status` on `error` value");
        if ($purchases == false) {
            $this->setLogMarker("A Provider Call returned `false` therefore we restart procedure for the next consecutive call");
            $params['stopLoop'] = true;

            return [];
        }

        $countOfPurchases = count($purchases);
        $this->setLogMarker("An amount of purchases is - $countOfPurchases");

        if ($countOfPurchases === 0) {
            $params['stopLoop'] = true;

            return [];
        }

        $params['recs'] += $countOfPurchases;
        $params['skip'] += $params['limit'];
        $params['page'] = intval($params['skip'] / $params['limit']) + 1;

        $this->setLogMarker("A checkpoint is where we check `status` on `success`");

        return $purchases;
    }

    protected function getParamsAndProvider($delta = '-30 minutes')
    {
        $provider = new $this->providerClass($this->task->task['ztype'], ['id_proc' => $this->idProc]);
        $period = $this->task->getPeriod($delta);
        $params = \Purchases::getParams();
        $params['period'] = $period;
        $params['task'] = $this->task->task;

        return [$provider, $period, $params];
    }

    protected function getTaskUpdPurchasesList()
    {
        return $this->task->updPurchasesList();
    }

    /**
     *
     * Runs a particular functionality flow
     *
     * @return void
     */
    public function processFlow(): void
    {
        if (method_exists($this, static::$flow[$this->task->task['zprocedure']])) {
            $this->{static::$flow[$this->task->task['zprocedure']]}();
            $this->task->setStatus(1);
        } else {
            $this->setLogMarker(
                "There is noncallable type of column `zprocedure` - {$this->task->task['zprocedure']}. 
                Please check the table `Task`! Postpone the current procedure..."
            );
            $this->task->setStatus(4);
        }

        $this->db->close();
    }

    abstract protected function getDocs();
}