<?php

/**
 * Largely inspired from Symfony Framework Bundle
 */

// ==== THIS MESSAGE TO MARK THIS FILE AS BUGGY ====

namespace Alchemy\Phrasea\Utilities;

use Alchemy\Phrasea\Application;
// use Silex\Translator;
use Symfony\Component\Translation\Translator;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Translation\MessageSelector;
use Pimple\Container;

/**
 * Translator that gets the current locale from the Silex application
 * and cache the translations on filesystem.
 */
class CachedTranslator extends Translator
{
    protected $app;
    protected $options = [
        'cache_dir' => null,
        'debug'     => false,
    ];

    public function __construct(Container $app, MessageSelector $selector, array $options = [])
    {
        $this->app = $app;

        if ($diff = array_diff(array_keys($options), array_keys($this->options))) {
            throw new \InvalidArgumentException(sprintf('The Translator does not support the following options: \'%s\'.', implode('\', \'', $diff)));
        }

        $this->options = array_merge($this->options, $options);

        parent::__construct($app['locale'], $selector, $this->options['cache_dir'], $this->options['debug']);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadCatalogue($locale)
    {
        if (isset($this->catalogues[$locale])) {
            return;
        }

        if (is_callable($this->options['cache_dir'])) {
            $cache_dir = call_user_func($this->options['cache_dir'], $this->app);
        } else {
            $cache_dir = $this->options['cache_dir'];
        }

        if (null === $cache_dir) {
            parent::loadCatalogue($locale);
            return;
        }

        $cache = new ConfigCache($cache_dir.'/catalogue.'.$locale.'.php', $this->options['debug']);
        if (!$cache->isFresh()) {
            parent::loadCatalogue($locale);

            $fallbackContent = '';
            $current = '';
            foreach ($this->computeFallbackLocales($locale) as $fallback) {
                $fallbackSuffix = ucfirst(str_replace('-', '_', $fallback));

                $fallbackContent .= sprintf(<<<EOF
\$catalogue%s = new MessageCatalogue('%s', %s);
\$catalogue%s->addFallbackCatalogue(\$catalogue%s);


EOF
                    ,
                    $fallbackSuffix,
                    $fallback,
                    var_export($this->catalogues[$fallback]->all(), true),
                    ucfirst(str_replace('-', '_', $current)),
                    $fallbackSuffix
                );
                $current = $fallback;
            }

            $content = sprintf(<<<EOF
<?php

use Symfony\Component\Translation\MessageCatalogue;

\$catalogue = new MessageCatalogue('%s', %s);

%s
return \$catalogue;

EOF
                ,
                $locale,
                var_export($this->catalogues[$locale]->all(), true),
                $fallbackContent
            );

            $cache->write($content, $this->catalogues[$locale]->getResources());

            return;
        }

        $this->catalogues[$locale] = include $cache;
    }
}
