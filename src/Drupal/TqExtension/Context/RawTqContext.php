<?php
/**
 * @author Sergey Bondarenko, <sb@firstvector.org>
 */
namespace Drupal\TqExtension\Context;

// Contexts.
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\DrupalExtension\Context as DrupalContexts;

// Exceptions.
use Behat\Behat\Context\Exception\ContextNotFoundException;

// Helpers.
use WebDriver\Session;
use Behat\Mink\Element\NodeElement;
use Behat\Behat\Hook\Scope\StepScope;
use Behat\Mink\Driver\Selenium2Driver;
use Symfony\Component\Console\Output\ConsoleOutput;
use Behat\Behat\Context\Environment\InitializedContextEnvironment;
use Drupal\TqExtension\Utils as TqUtils;

/**
 * @see RawTqContext::__call()
 *
 * @method User\UserContext getUserContext()
 * @method Form\FormContext getFormContext()
 * @method Email\EmailContext getEmailContext()
 * @method Drush\DrushContext getDrushContext()
 * @method Wysiwyg\WysiwygContext getWysiwygContext()
 * @method Redirect\RedirectContext getRedirectContext()
 * @method TqContext getTqContext()
 * @method DrupalContexts\MinkContext getMinkContext()
 * @method DrupalContexts\DrupalContext getDrupalContext()
 * @method DrupalContexts\MessageContext getMessageContext()
 * @method \Drupal\Component\Utility\Random getRandom()
 */
class RawTqContext extends RawPageContext implements TqContextInterface
{
    /**
     * Project base URL.
     *
     * @var string
     */
    private static $baseUrl = '';
    /**
     * Parameters of TqExtension.
     *
     * @var array
     */
    private $parameters = [];
    /**
     * A set of tags for each scenario.
     *
     * @var array
     */
    protected static $tags = [];
    /**
     * @var string
     */
    protected $pageUrl = '';

    /**
     * @param string $method
     * @param array $arguments
     *
     * @throws \Exception
     * @throws ContextNotFoundException
     *   When context class cannot be loaded.
     *
     * @return SnippetAcceptingContext
     */
    public function __call($method, array $arguments)
    {
        $environment = $this->getEnvironment();
        // @example
        // The "getFormContext" method is not declared and his name will be split by capital
        // letters, creating an array with three items: "get", "Form" and "Context".
        list(, $base, $context) = preg_split('/(?=[A-Z])/', $method);

        foreach ([
            [$this->getTqParameter('context_namespace'), $base],
            ['Drupal', 'DrupalExtension', 'Context'],
        ] as $class) {
            $class[] = "$base$context";
            $class = implode('\\', $class);

            if ($environment->hasContextClass($class)) {
                return $environment->getContext($class);
            }
        }

        throw new \Exception(sprintf('Method %s does not exist', $method));
    }

    /**
     * @param array $variables
     *   An associative array where key is a variable name and a value - value.
     */
    public static function setDrupalVariables(array $variables)
    {
        foreach ($variables as $name => $value) {
            variable_set($name, $value);
        }
    }

    /**
     * Get selector by name.
     *
     * @param string $name
     *   Selector name from the configuration file.
     *
     * @return string
     *   CSS selector.
     *
     * @throws \Exception
     *   If selector does not exits.
     */
    public function getDrupalSelector($name)
    {
        $selectors = $this->getDrupalParameter('selectors');

        if (!isset($selectors[$name])) {
            throw new \Exception(sprintf('No such selector configured: %s', $name));
        }

        return $selectors[$name];
    }

    /**
     * @return string
     *   Clean base url without any suffixes.
     */
    public function getBaseUrl()
    {
        if (empty(self::$baseUrl)) {
            $url = parse_url($this->getMinkParameter('base_url'));
            self::$baseUrl = sprintf('%s://%s', $url['scheme'], $url['host']);
        }

        return self::$baseUrl;
    }

    /**
     * @return string
     *   URL to files directory.
     */
    public function getFilesUrl()
    {
        return $this->getBaseUrl() . '/sites/default/files';
    }

    protected function processJavaScript(&$text)
    {
        $text = str_replace(['$'], ['jQuery'], $text);

        return $this;
    }

    /**
     * @return InitializedContextEnvironment
     */
    public function getEnvironment()
    {
        return $this->getDrupal()->getEnvironment();
    }

    /**
     * @return Selenium2Driver
     */
    public function getSessionDriver()
    {
        return $this->getSession()->getDriver();
    }

    /**
     * @return Session
     */
    public function getWebDriverSession()
    {
        return $this->getSessionDriver()->getWebDriverSession();
    }

    /**
     * @todo Remove this when DrupalExtension will be used Mink >=1.6 and use $this->getSession->getWindowNames();
     *
     * @return string[]
     */
    public function getWindowNames()
    {
        return $this->getWebDriverSession()->window_handles();
    }

    /**
     * @param NodeElement $element
     * @param string $script
     *
     * @example
     * $this->executeJsOnElement($this->element('*', 'Meta tags'), 'return jQuery({{ELEMENT}}).text();');
     * $this->executeJsOnElement($this->element('*', '#menu'), '{{ELEMENT}}.focus();');
     *
     * @throws \Exception
     *
     * @return string
     */
    public function executeJsOnElement(NodeElement $element, $script)
    {
        $session = $this->getWebDriverSession();
        // We need to trigger something with "withSyn" method, because, otherwise an element won't be found.
        $element->focus();

        $this->processJavaScript($script)->debug([$script]);

        return $session->execute([
            'script' => str_replace('{{ELEMENT}}', 'arguments[0]', $script),
            'args' => [['ELEMENT' => $session->element('xpath', $element->getXpath())->getID()]],
        ]);
    }

    /**
     * @param array $strings
     *
     * @return self
     */
    public function debug(array $strings)
    {
        if ($this->hasTag('debug')) {
            $this->consoleOutput('question', 4, array_merge(['DEBUG:'], $strings));
        }

        return $this;
    }

    public function executeJs($javascript, array $args = [])
    {
        $javascript = format_string($javascript, $args);

        return $this
            ->processJavaScript($javascript)
            ->debug([$javascript])
            ->getSession()
            ->evaluateScript($javascript);
    }

    /**
     * @param string $url
     *
     * @return string
     */
    public function unTrailingSlashIt($url)
    {
        return trim($url, '/');
    }

    /**
     * Check JS events in step definition.
     *
     * @param StepScope $event
     *
     * @return int
     */
    public static function isStepImpliesJsEvent(StepScope $event)
    {
        return preg_match('/(follow|press|click|submit)/i', $event->getStep()->getText());
    }

    /**
     * @return \Drupal\Driver\DrushDriver
     */
    public function getDrushDriver()
    {
        return $this->getDriver('drush');
    }

    /**
     * Wait for all AJAX requests and jQuery animations.
     */
    public function waitAjaxAndAnimations()
    {
        $this->getSession()
             ->wait(1000, "window.__behatAjax === false && !jQuery(':animated').length && !jQuery.active");
    }

    /**
     * @param string $tag
     *   The name of tag.
     *
     * @return bool
     *   Indicates the state of tag existence in a feature and/or scenario.
     */
    public function hasTag($tag)
    {
        return isset(self::$tags[$tag]);
    }

    /**
     * @param string $tag
     *   The name of tag.
     *
     * @return string
     *   Tag value or an empty string.
     */
    public function getTag($tag)
    {
        return $this->hasTag($tag) ? self::$tags[$tag] : '';
    }

    /**
     * {@inheritdoc}
     */
    public function setTqParameters(array $parameters)
    {
        if (empty($this->parameters)) {
            $this->parameters = $parameters;
        }
    }

    /**
     * @param string $name
     *   The name of parameter from behat.yml.
     *
     * @return mixed
     */
    public function getTqParameter($name)
    {
        return isset($this->parameters[$name]) ? $this->parameters[$name] : false;
    }

    /**
     * @param string $type
     *   Could be "comment",
     * @param int $indent
     *   Number of spaces.
     * @param array $strings
     *   Paragraphs.
     *
     * @link http://symfony.com/doc/current/components/console/introduction.html
     */
    public function consoleOutput($type, $indent, array $strings)
    {
        $indent = implode(' ', array_fill_keys(range(0, $indent), ''));
        $arguments = func_get_args();
        // Remove the "indent" and "strings" parameters from an array with arguments.
        unset($arguments[1], $arguments[2]);

        // Replace the "type" argument by message that will be printed.
        $arguments[0] = "$indent<$type>" . implode(PHP_EOL . "</$type>$indent<$type>", $strings) . "</$type>";

        (new ConsoleOutput)->writeln(call_user_func_array('sprintf', $arguments));
    }
}
