<?php

namespace App\Cron;

if (!defined("BASE_PATH")) {
    define("BASE_PATH", realpath(dirname(realpath(__FILE__)) . '/../'));
}

require_once(BASE_PATH . '/cron/getdata_base.php');

class GetDataMultiNoneComm extends GetDataBase
{
    public function __construct()
    {
        parent::__construct();
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
        $purchases = $this->task->getPurchasesListMulti($params['limit'], $this->idProc);

        $this->setLogMarker("An amount of purchases after `getPurchasesList` is - " . count($purchases));
        $this->setLogMarker("For purchases key consistency is - ", $purchases);
        if (!$purchases) {    //Не осталось в списке необработанных
            echo 'And whats doing???';
            $this->setLogMarker('Nothing to do!');

            return;
        }

        $purch = new \Purchase($this->task->task['rtype']);
        $provider = new $this->providerClass($this->task->task['ztype'], ['id_proc' => $this->idProc]);

        foreach ($purchases as $key => $purchase) {
            $purchs[$key] = $purchase['purchase_number'];
        }

        $docs = $provider->getPurchaseDocsMulti($purchs);

        $this->setLogMarker("A status of provider after calling Docs is - {$provider->status}");

        $count = 0;
        if (is_array($docs)) {
            $this->setLogMarker("An amount of docs is - " . count($docs));
        }

        foreach ($purchases as $key => $purchase) {
            $this->setLogMarker("A current Purchase's key is - $key");
            $this->setLogMarker("A current Purchase is - ", $purchase);
            $this->setLogMarker("A current Doc is - ", $docs[$purchase['purchase_number']]);
            if (
                    !isset($docs[$purchase['purchase_number']])
                    || $docs[$purchase['purchase_number']]['status'] == 'error'
                    || $docs[$purchase['purchase_number']]['status_code'] == 404
            ) {
                $params['provider'] = $provider;
                $params['purchase_number'] = $purchases[$key]['purchase_number'];
                $params['http_status_code'] = $error = $docs[$purchase['purchase_number']]['status'] == 'error'
                    ? $docs[$purchase['purchase_number']]['status']
                    : $docs[$purchase['purchase_number']]['status_code'];
                $this->setLogMarker("An error has been registered: $error");
                $this->setLogMarker('An error has happened. Dump of params is: ', $params);
                $this->setLogMarker('An error has happened. Dump of Docs is: ', $docs[$purchase['purchase_number']]);

                $this->task->setDone($purchase['id'], -1);

                continue;
            }

            unset(
                $docs[$purchase['purchase_number']]['status'],
                $docs[$purchase['purchase_number']]['error'],
                $docs[$purchase['purchase_number']]['status_code']
            );

            $is223Task = $this->task->task['ztype'] == \AbstractConstants::GOVERNMENT_COMMERCIAL_TYPE;
            $mappingSource = $is223Task
                ? $docs[$purchase['purchase_number']]['response']
                : $docs[$purchase['purchase_number']]['response']['docs'];

            try {
                $data = \PurchasesDocs::prepareData($mappingSource, $purchase);
                $this->setLogMarker("A current data of a Doc is - ", $data);
                $purch->savePurchaseDocs($data, $docs[$purchase['purchase_number']]['response'], true, !$is223Task);
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
                $this->task->setDone($purchase['id']);
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

(new GetDataMultiNoneComm())
    ->setUpEnvironment()
    ->processFlow();