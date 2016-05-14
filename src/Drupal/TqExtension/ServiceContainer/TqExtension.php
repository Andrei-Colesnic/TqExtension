<?php
/**
 * @author Sergey Bondarenko, <sb@firstvector.org>
 */
namespace Drupal\TqExtension\ServiceContainer;

use Behat\Behat\Context\ServiceContainer\ContextExtension;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\ServiceContainer\ServiceProcessor;
use Behat\Testwork\Environment\ServiceContainer\EnvironmentExtension;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class TqExtension implements Extension
{
    /**
     * @var ServiceProcessor
     */
    private $processor;
    private $baseNamespace = '';

    /**
     * Initializes compiler pass.
     *
     * @param null|ServiceProcessor $processor
     */
    public function __construct(ServiceProcessor $processor = null)
    {
        $this->processor = $processor ? : new ServiceProcessor;
        $this->baseNamespace = implode('\\', array_slice(explode('\\', __NAMESPACE__), 0, -1));
    }

    public function contextNamespace($suffix = '')
    {
        return "$this->baseNamespace\\Context" . ($suffix ? "\\$suffix" : '');
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigKey()
    {
        return 'tq';
    }

    /**
     * {@inheritDoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $definition = new Definition($this->contextNamespace('TqContextInitializer'), [
            $config,
            $this->contextNamespace()
        ]);
        $definition->addTag(ContextExtension::INITIALIZER_TAG, ['priority' => 0]);

        $container->setDefinition(ContextExtension::INITIALIZER_TAG . '.initializer', $definition);
    }

    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container)
    {
        $definition = new Definition($this->contextNamespace('TqContextReader'));
        $definition->addTag(EnvironmentExtension::READER_TAG, ['priority' => 50]);

        foreach ($this->processor->findAndSortTaggedServices($container, ContextExtension::READER_TAG) as $reference) {
            $definition->addMethodCall('registerContextReader', [$reference]);
        }

        $container->setDefinition(EnvironmentExtension::READER_TAG . '.context', $definition);
    }

    /**
     * {@inheritDoc}
     *
     * @link http://symfony.com/doc/current/components/config/definition.html
     *
     * @example
     * Drupal\TqExtension:
     *   wait_for_redirect: 60
     *   email_account_strings: get_account_strings_for_email
     *   email_accounts:
     *     account_alias:
     *       imap: imap.gmail.com:993/imap/ssl
     *       email: example1@email.com
     *       password: p4sswDstr_1
     *     administrator:
     *       imap: imap.gmail.com:993/imap/ssl
     *       email: example2@email.com
     *       password: p4sswDstr_2
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $config = $builder->children();

        foreach ([
            'wait_for_redirect' => [
                'defaultValue' => 30,
                'info' => 'The timeout (in seconds) for waiting opening a page',
            ],
            'wait_for_email' => [
                'defaultValue' => 30,
                'info' => 'This timeout will be used if you checking an email via IMAP',
            ],
            'email_account_strings' => [
                'defaultValue' => '',
                'info' => 'See detailed description in "docs/examples/EMAIL.md"',
            ],
        ] as $scalarNode => $data) {
            $config = $config->scalarNode($scalarNode)
                ->defaultValue($data['defaultValue'])
                ->info($data['info'])
                ->end();
        }

        $config = $config->arrayNode('email_accounts')
            ->requiresAtLeastOneElement()
            ->prototype('array')
            ->children();

        foreach ([
            'imap' => 'IMAP url without parameters. For example: imap.gmail.com:993/imap/ssl',
            'username' => 'Login from an e-mail account',
            'password' => 'Password from an e-mail account',
        ] as $scalarNode => $info) {
            $config = $config->scalarNode($scalarNode)
                ->isRequired()
                ->cannotBeEmpty()
                ->info($info)
                ->end();
        }

        $config->end()->end()->end()->end();
    }
}
