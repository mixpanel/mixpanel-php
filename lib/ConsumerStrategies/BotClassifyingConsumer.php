<?php
require_once(dirname(__FILE__) . "/AbstractConsumer.php");
require_once(dirname(__FILE__) . "/../BotClassifier/AiBotClassifier.php");

/**
 * Consumer wrapper that classifies AI bots in tracked event batches.
 * Wraps any other consumer and enriches event data with bot classification
 * properties when a user-agent string is present.
 */
class ConsumerStrategies_BotClassifyingConsumer extends ConsumerStrategies_AbstractConsumer {
    /** @var ConsumerStrategies_AbstractConsumer */
    private $_innerConsumer;
    /** @var BotClassifier_AiBotClassifier */
    private $_classifier;
    /** @var string */
    private $_userAgentProperty = '$user_agent';
    /** @var array */
    private $_consumers = array(
        "file"   => "ConsumerStrategies_FileConsumer",
        "curl"   => "ConsumerStrategies_CurlConsumer",
        "socket" => "ConsumerStrategies_SocketConsumer"
    );

    function __construct($options = array()) {
        parent::__construct($options);
        $inner_key = isset($options["bot_classifying_inner_consumer"])
            ? $options["bot_classifying_inner_consumer"] : "curl";
        // NOTE: Do NOT merge $options["consumers"] into $_consumers here.
        // The "consumers" key in $options is also consumed by Producers_MixpanelBaseProducer,
        // and merging it would include "bot_classifying" => self, risking self-instantiation.
        // Only the three hardcoded consumer types (file, curl, socket) are valid inner consumers.
        $InnerClass = $this->_consumers[$inner_key];
        $this->_innerConsumer = new $InnerClass($options);
        $additional_bots = isset($options["bot_additional_patterns"])
            ? $options["bot_additional_patterns"] : array();
        $this->_classifier = new BotClassifier_AiBotClassifier($additional_bots);
        if (isset($options["bot_user_agent_property"])) {
            $this->_userAgentProperty = $options["bot_user_agent_property"];
        }
    }

    /**
     * Classify bot user-agents in each message and forward to inner consumer.
     * @param array $batch
     * @return boolean
     */
    public function persist($batch) {
        foreach ($batch as &$message) {
            if (isset($message["properties"]) && isset($message["properties"][$this->_userAgentProperty])) {
                $classification = $this->_classifier->classify($message["properties"][$this->_userAgentProperty]);
                $message["properties"] = array_merge($message["properties"], $classification);
            }
        }
        unset($message);
        return $this->_innerConsumer->persist($batch);
    }

    /** @return int */
    public function getNumThreads() {
        return $this->_innerConsumer->getNumThreads();
    }
}
