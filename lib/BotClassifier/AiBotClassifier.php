<?php
require_once(dirname(__FILE__) . "/AiBotDatabase.php");

/**
 * Classifies user-agent strings against a database of known AI bot patterns.
 */
class BotClassifier_AiBotClassifier {
    /** @var array The bot patterns to check against (built-in + any custom) */
    private $_patterns;

    /**
     * @param array $additional_bots Optional additional bot patterns to prepend (checked first)
     */
    public function __construct($additional_bots = array()) {
        $this->_patterns = array_merge($additional_bots, BotClassifier_AiBotDatabase::getDatabase());
    }

    /**
     * Classify a user-agent string against the AI bot database.
     * @param string|null $user_agent
     * @return array Classification result with '$is_ai_bot' (always present) and optional
     *               '$ai_bot_name', '$ai_bot_provider', '$ai_bot_category'
     */
    public function classify($user_agent) {
        if ($user_agent === null || $user_agent === "" || !is_string($user_agent)) {
            return array('$is_ai_bot' => false);
        }
        foreach ($this->_patterns as $bot) {
            if (preg_match($bot["pattern"], $user_agent)) {
                return array(
                    '$is_ai_bot'      => true,
                    '$ai_bot_name'     => $bot["name"],
                    '$ai_bot_provider' => $bot["provider"],
                    '$ai_bot_category' => $bot["category"]
                );
            }
        }
        return array('$is_ai_bot' => false);
    }

    /**
     * Create a classifier with optional additional bot patterns (checked before built-in).
     * @param array $options Options array with optional 'additional_bots' key
     * @return BotClassifier_AiBotClassifier
     */
    public static function createClassifier($options = array()) {
        $additional = isset($options["additional_bots"]) ? $options["additional_bots"] : array();
        return new BotClassifier_AiBotClassifier($additional);
    }

    /**
     * Return bot database for inspection (no regex patterns exposed).
     * @return array
     */
    public function getBotDatabase() {
        return BotClassifier_AiBotDatabase::getDatabaseForInspection();
    }
}
