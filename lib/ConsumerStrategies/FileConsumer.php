<?php
require_once(dirname(__FILE__) . "/AbstractConsumer.php");
// Guard against a second copy of the SDK (e.g. bundled by another plugin) being loaded.
if (!class_exists('ConsumerStrategies_FileConsumer', false)) {

/**
 * Consumes messages and writes them to a file
 */
class ConsumerStrategies_FileConsumer extends ConsumerStrategies_AbstractConsumer {

    /**
     * @var string path to a file that we want to write the messages to
     */
    private $_file;


    /**
     * Creates a new FileConsumer and assigns properties from the $options array
     * @param array $options
     */
    function __construct($options) {
        parent::__construct($options);

        // what file to write to?
        $this->_file = isset($options['file']) ? $options['file'] :  dirname(__FILE__)."/../../messages.txt";
    }


    /**
     * Append $batch to a file
     * @param array $batch
     * @return bool
     */
    public function persist($batch) {
        if (count($batch) > 0) {
            return file_put_contents($this->_file, json_encode($batch)."\n", FILE_APPEND | LOCK_EX) !== false;
        } else {
            return true;
        }
    }
}

}
