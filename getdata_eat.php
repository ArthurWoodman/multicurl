<?php

namespace App\Cron;

if (!defined("BASE_PATH")) {
    define("BASE_PATH", realpath(dirname(realpath(__FILE__)) . '/../'));
}

require_once(BASE_PATH . '/assets/includes/db.php');
require_once(BASE_PATH . '/assets/includes/task.php');
require_once(BASE_PATH . '/assets/includes/purchase.php');
require_once(BASE_PATH . '/cron/getdata_base.php');

class GetDataEAT extends GetDataBase
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Gets purchases for a required time period
     *
     * @return void
     */
    protected function getPurchases(): void
    {
        $this->task->logAction("Get Reestr procedure has been started");
        list($provider, $period, $params) = $this->getParamsAndProvider();
        $purch = new \Purchase($this->task->task['rtype']);

        $this->setLogMarker("A checkpoint is before do/while...");

        do {
            $purchases = $this->callProvider($params, $provider);

            if ($provider->status == 'processing') {
                return;
            }

            if (isset($params['stopLoop']) && $params['stopLoop']) {
                break;
            }

            if ($provider->status == self::PROVIDER_STATUS_SUCCESS) {
                foreach ($purchases as $purchase) {
                    $this->setLogMarker("A checkpoint is where we started `foreach` for purchases");

                    try {
                        $data = \Purchases::prepareData($purchase);
                        $this->setLogMarker("A current purchase we are dealing with is - ", $data);
                        $purch->savePurchase($data);
                    } catch (\Exception $e) {
                        $this->setLogMarker("An exception code during the saving procedure is - {$e->getCode()}");
                        $this->setLogMarker("An exception during the saving procedure is - {$e->getMessage()}");

                        die('We stop execution because we need to see why we could\'t save a purchase into DB!');
                    }

                    $this->setLogMarker("A checkpoint is after saving a purchase");

                    if ($purch->errno) {
                        $this->setLogMarker("Ошибка записи в БД: {$purch->error}; Номер закупки: {$purchase['purchase_number']}");
                        $this->db->close();

                        die();
                    }
                }
            }

            $this->setLogMarker("A checkpoint is when we finished with `foreach` for purchases");

            if (isset($params['onestep'])) {
                break;
            }
        } while ($provider->status == self::PROVIDER_STATUS_SUCCESS);

        $this->setLogMarker("A checkpoint is when we finished with `foreach` for purchases");

        if ($provider->status == self::PROVIDER_STATUS_SUCCESS) {
            $this->task->logAction(
                "Обработка записей за период с {$period['date_start']} по {$period['date_end']} выпонена успешно. 
                 Всего добавлено {$params['recs']} записей."
            );
        }
    }

    /**
     * Gets documents of purchases for a required time period.
     * The following is not refactored...
     * Needs to be reviewed and refactored if `Docs loading` would have problems!
     *
     * @return void
     */
    protected function getDocs()
    {
        $this->task->logAction("Get Docs procedure has been started");
        $params = \PurchasesDocs::getParams();
        $processGroup = $this->task->getProcessGroup();
        $purchases = $this->task->getPurchasesList($params['limit']);

        $this->setLogMarker("An amount of purchases after `getPurchasesList` is - " . count($purchases));
        if (!$purchases) {    //Не осталось в списке необработанных
            echo 'And whats doing???';
            $this->setLogMarker('Nothing to do!');
            return;
        }

        $purchs = [];
        $purch = new \Purchase($this->task->task['rtype']);
        $provider = new $this->providerClass($this->task->task['ztype'], ['id_proc' => $this->idProc]);

        foreach ($purchases as $key => $purchase) {
            $purchs[$key] = $purchase['purchase_number'];
        }

        $docs = $provider->getPurchaseDocs($purchs);

        $this->setLogMarker("A status of provider after calling Docs is - {$provider->status}");

        if ($provider->status == self::PROVIDER_STATUS_PROCESSING) {
            return;
        }

        if (!$docs) {
            $this->setLogMarker(
                "Warning no data from {$this->providerClass}; Purchase_number={$purchase['purchase_number']}"
            );
            $this->task->setDone($purchase['id'], 2);
            return;
        }

        $count = 0;
        if (is_array($docs)) {
            $this->setLogMarker("An amount of docs is - " . count($docs));
        }
        foreach ($docs as $key => $doc) {
            $this->setLogMarker("A marker of starting a new loop of Docs is.");
            if ($doc['status'] == 'error') {
                $params['provider'] = $provider;
                $params['purchase_number'] = $purchases[$key]['purchase_number'];
                $this->setLogMarker("An error has been registered: {$doc['error']}");
                $this->setLogMarker('An error has happened. Dump of params is: ', $params);
                $this->setLogMarker('An error has happened. Dump of Docs is: ', $doc);

                continue;
            }

            try {
                $data = \PurchasesDocs::prepareData($doc, $purchase);
                $this->setLogMarker("A current data of a Doc is - ", $data);
                $purch->savePurchaseDocs($data, $doc);
            } catch (\Exception $e) {
                $this->setLogMarker("An exception code during the Doc saving procedure is - {$e->getCode()}");
                $this->setLogMarker("An exception during the Doc saving procedure is - {$e->getMessage()}");

                die('We stop execution because we need to see why we could\'t save Doc into DB!');
            }

            if ($purch->errno) {
                $this->setLogMarker("Ошибка записи документов в БД: {$purch->error}; Номер закупки: {$purchase['purchase_number']}");
                $this->task->setStatus(4);
                $this->db->close();

                die();
            }

            if ($provider->status == self::PROVIDER_STATUS_SUCCESS) {
                $this->setLogMarker("A current Doc's key is - $key");
                $this->setLogMarker("A current Purchase is - ", $purchases[$key]);
                $this->task->setDone($purchases[$key]['id']);
            }

            $count++;
        }

        if ($provider->status == self::PROVIDER_STATUS_SUCCESS) {
            $this->task->logAction(
                "Обработка документов закупок группа $processGroup выпонена успешно. Загружены данные документов для $count закупок."
            );
        }
    }
}

(new GetDataEAT())
    ->setUpEnvironment()
    ->processFlow();