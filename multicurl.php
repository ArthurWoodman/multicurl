<?php

trait MultiCurl
{
    public function getPurchaseDocsMulti($purchase_numbers): array
    {
        foreach($purchase_numbers as $purchase_number) {
            $this->requests[$purchase_number]['url'] = self::ApiPath . $this->createURI() . urlencode($purchase_number);
        }

        $results = $this->sendMultiGetRequest();

        $resl = [];
        foreach($results as $key => $result) {
            if (!$result) {
                $resl[$key]['status'] = 'error';
                $resl[$key]['error'] = is_array($result) ? 'Ошибка получения данных': $result;
            } else {
                $resl[$key] = $result;
                $resl[$key]['status'] = 'success';
                $resl[$key]['error'] = '';
            }
        }

        $this->status = 'success';

        return $resl;
    }

    private function sendMultiGetRequest(): array
    {
        $this->error = '';
        $result = [];

        $mcurl = curl_multi_init();
        $map = new \WeakMap();
        $curl = [];
        foreach($this->requests as $key => $request)
        {
            $curl[$key] = curl_init($request['url']);

            curl_setopt($curl[$key], CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl[$key], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl[$key], CURLOPT_SSL_VERIFYPEER, 0);
            $this->setHeaders($curl[$key]);

            $map[$curl[$key]] = [];
            $map[$curl[$key]][] = $request['url'];
            $map[$curl[$key]][] = $key;
            curl_multi_add_handle($mcurl, $curl[$key]);
        }

        do {
            $status = curl_multi_exec($mcurl, $unfinishedHandles );

            if ($status !== CURLM_OK) {
                throw new \Exception(curl_multi_strerror(curl_multi_errno($mcurl)));
            }

            while (($info = curl_multi_info_read($mcurl)) !== false) {
                $handle = $info['handle'];
                $statusCode = curl_getinfo($handle, \CURLINFO_HTTP_CODE);
                if ($info['msg'] === \CURLMSG_DONE) {
                    curl_multi_remove_handle($mcurl, $handle);
                    $url = $map[$handle][0];
                    curl_close($handle);
                }

                if ($info['result'] === \CURLE_OK) {
                    $content = curl_multi_getcontent($handle);
                    $result[$map[$handle][1]] = [];
                    $result[$map[$handle][1]]['response'] = json_decode($content,1);
                    $result[$map[$handle][1]]['status_code'] = $statusCode;
                } else {
                    error_log("Request to {$url} failed with code $statusCode and error: " . curl_strerror($info['result']));
                }
            }

            if ($unfinishedHandles) {
                if (($updatedHandles = curl_multi_select($mcurl)) === -1) {
                    throw new \Exception(curl_multi_strerror(curl_multi_errno($mcurl)));
                }
            }
        } while ($unfinishedHandles);

        curl_multi_close($mcurl);

        return $result;
    }
}