<?php

class EventTrigger extends UtilityAppModel {

    var $cacheQueries = true;

    function afterFind($results, $primary) {
        if ($primary === true) {
            foreach ($results as $idx => $record) {
                $evt = $record['EventTrigger'];
                if (!empty($evt['criteria']) && !is_array($evt['criteria'])) {
                    $evt['criteria'] = unserialize($evt['criteria']);
                }
                if (!empty($evt['callback_arguments']) && !is_array($evt['callback_arguments'])) {
                    $evt['callback_arguments'] = unserialize($evt['callback_arguments']);
                }
                $results[$idx]['EventTrigger'] = $evt;
            }
            return $results;
        }
    }
    
    function beforeSave($options) {
        if (!empty($this->data['EventTrigger'])) {
            $evt = $this->data['EventTrigger'];
            if (isset($evt['criteria']) && is_array($evt['criteria'])) {
                $evt['criteria'] = serialize($evt['criteria']);
            }
            if (isset($evt['callback_arguments']) && is_array($evt['callback_arguments'])) {
                $evt['callback_arguments'] = serialize($evt['callback_arguments']);
            }
            $evt['modified'] = date('Y-m-d H:i:s');
            $this->data['EventTrigger'] = $evt;
        }
        return true;
    }

}

?>