<?php

namespace Bolt\Extension\Animal\Translate;

use Bolt\Extension\Animal\Translate\Config;
use Bolt\Extension\SimpleExtension;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Translate extension class.
 *
 * @author Svante Richter <svante.richter@gmail.com>
 */
class TranslateExtension extends SimpleExtension
{
    /**
     * @inheritdoc
     */
    protected function registerServices(Application $app)
    {
        $this->registerTranslateServices($app);
        $this->registerOverrides($app);

        $app->before([$this, 'before']);
    }

    /**
     * Before handler that sets the localeSlug for future use and sets the
     * locales global in twig.
     *
     * @param Request     $request
     * @param Application $app
     */
    public function before(Request $request, Application $app)
    {
        $this->registerTwigGlobal($app);
    }

    /**
     * {@inheritdoc}
     */
    protected function subscribe(EventDispatcherInterface $dispatcher)
    {
        $app = $this->getContainer();
        $dispatcher->addSubscriber(new EventListener\StorageListener($app['config'], $app['translate.config'], $app['query'], $app['request_stack']));
    }

    /**
     * {@inheritdoc}
     */
    public function getServiceProviders()
    {
        return [
            $this,
            new Provider\FieldProvider(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return [
            'templates' => ['position' => 'prepend', 'namespace' => 'bolt'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'localeswitcher' => ['localeSwitcher', ['is_variadic' => true]],
        ];
    }

    /**
     * Register translate services/values on the app container
     *
     * @param Application $app
     */
    private function registerTranslateServices(Application $app)
    {
        $app['translate'] = $app->share(
            function () {
                return $this;
            }
        );
        $app['translate.config'] = $app->share(
            function () {
                return new Config\Config($this->getConfig());
            }
        );
        $app['translate.slug'] = $app->share(
            function ($app) {
                /** @var Config\Config $config */
                $config = $app['translate.config'];
                $request = $app['request_stack']->getCurrentRequest();
                /** @var Config\Locale $locale */
                $locale = reset($config->getLocales());
                $defaultSlug = $locale->getSlug();

                if ($request === null) {
                    return $defaultSlug;
                }

                $localeSlug = $request->get('_locale', $defaultSlug);
                if ($config->getLocale($localeSlug) !== null) {
                    return $config->getLocale($localeSlug)->getSlug();
                }

                foreach ($config->getLocales() as $locale) {
                    if ($localeSlug == $locale->getSlug()) {
                        return $localeSlug;
                    }
                }

                return $defaultSlug;
            }
        );
    }

    /**
     * Register overrides for Bolt's services
     *
     * @param Application $app
     */
    private function registerOverrides(Application $app)
    {
        $app['storage.legacy'] = $app->extend(
            'storage.legacy',
            function ($storage) use ($app) {
                return new Storage\Legacy($app);
            }
        );

        $app['controller.frontend'] = $app->share(
            function ($app) {
                $frontend = new Frontend\LocalizedFrontend();
                $frontend->connect($app);

                return $frontend;
            }
        );

        $app['url_generator'] = $app->extend(
            'url_generator',
            function ($urlGenerator) use ($app) {
                $requestContext = $urlGenerator->getContext();

                if (is_null($requestContext->getParameter('_locale'))) {
                    $config = $app['translate.config'];
                    /** @var Config\Locale $locale */
                    $locale = reset($config->getLocales());
                    $defaultSlug = $locale->getSlug();

                    $requestContext->setParameter('_locale', $defaultSlug);
                }

                return $urlGenerator;
            }
        );

        if ($app['translate.config']->isMenuOverride()) {
            $app['menu'] = $app->share(
                function ($app) {
                    return new Frontend\LocalizedMenuBuilder($app);
                }
            );
        }

        $app['schema.content_tables'] = $app->extend(
            'schema.content_tables',
            function ($contentTables) use ($app) {
                $config = $app['translate.config'];
                $platform = $app['db']->getDatabasePlatform();
                $prefix = $app['schema.prefix'];
                $contentTypes = $app['config']->get('contenttypes');

                foreach (array_keys($contentTypes) as $contentType) {
                    $contentTables[$contentType] = $app->share(function () use ($platform, $prefix, $config) {
                        return new Storage\ContentTypeTable($platform, $prefix, $config);
                    });
                }

                return $contentTables;
            }
        );
    }

    /**
     * Register twig global
     *
     * @param Application $app
     */
    private function registerTwigGlobal(Application $app)
    {
        $app['twig'] = $app->extend(
            'twig',
            function (\Twig_Environment $twig) use ($app) {
                $twig->addGlobal('locales', $this->getCurrentLocaleStructure());

                return $twig;
            }
        );
    }

    /**
     * Helper to get a the current locale structure
     *
     * @return array
     */
    public function getCurrentLocaleStructure()
    {
        $app = $this->getContainer();
        $config = $app['translate.config'];
        $locales = $config->getLocales();
        $request = $app['request_stack']->getCurrentRequest();
        if ($request === null) {
            return $locales;
        }

        /** @var Config\Locale $locale */
        foreach ($locales as $iso => $locale) {
            $requestAttributes = $request->attributes->get('_route_params');
            $requestLocale = isset($requestAttributes['_locale']) ? $requestAttributes['_locale'] : null;
            if ($config->isTranslateSlugs() && $locale->getSlug() !== $requestLocale && $request->get('slug')) {
                $repo = $app['storage']->getRepository('pages');
                $qb = $repo->createQueryBuilder();
                $qb->select($locale->getSlug() . '_slug')
                    ->where($requestAttributes['_locale'] . '_slug = ?')
                    ->setParameter(0, $request->get('slug'))
                ;
                $newSlug = $repo->findOneWith($qb);
                if ($newSlug) {
                    $requestAttributes['slug'] = $newSlug;
                }
            }

            $requestAttributes['_locale'] = $locale->getSlug();
            $locale->setUrl($app['url_generator']->generate($request->get('_route'), $requestAttributes));

            if ($app['translate.slug'] === $locale->getSlug()) {
                $locale->setActive(true);
            }
        }

        return $locales;
    }

    /**
     * Twig helper to render a locale switcher on the frontend
     *
     * @param array $args
     *
     * @return \Twig_Markup
     */
    public function localeSwitcher(array $args = [])
    {
        $defaults = [
              'classes'  => '',
              'template' => '@bolt/frontend/_localeswitcher.twig',
        ];
        $args = array_merge($defaults, $args);

        $html = $this->renderTemplate($args['template'], [
            'classes' => $args['classes'],
        ]);

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'locales' => [],
        ];
    }
}
