<?php

use Mixpanel\ConsumerStrategies\AbstractConsumer;

class ObConsumer extends AbstractConsumer {
    public function persist($batch) {

        if (isset($batch[0]['event']) && $batch[0]['event'] == "force_error") {
            $this->_handleError(0, "This is the data from a fake error");
            return false;
        } else {
            echo "<pre>";
            echo "printing batch:\n";
            echo "---------------\n";
            echo json_encode($batch) . "\n";
            echo "---------------\n\n";
            echo "</pre>";

            return true;
        }
    }
}