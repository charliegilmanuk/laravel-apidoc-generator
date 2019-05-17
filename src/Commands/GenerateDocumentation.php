<?php

namespace Mpociot\ApiDoc\Commands;

use ReflectionClass;
use ReflectionException;
use Illuminate\Routing\Route;
use Illuminate\Console\Command;
use Mpociot\Reflection\DocBlock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Mpociot\ApiDoc\Tools\Generator;
use Mpociot\ApiDoc\Tools\RouteMatcher;
use Mpociot\Documentarian\Documentarian;
use Mpociot\ApiDoc\Postman\CollectionWriter;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'apidoc:generate
                            {--force : Force rewriting of existing routes}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate your API documentation from existing Laravel routes.';

    private $routeMatcher;

    public $infoText;
    public $frontmatter;
    public $prependFileContents;
    public $appendFileContents;
    public $parsedRoutes;
    public $settings;

    public function __construct(RouteMatcher $routeMatcher)
    {
        parent::__construct();
        $this->routeMatcher = $routeMatcher;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        URL::forceRootUrl(config('app.url'));
        $usingDingoRouter = strtolower(config('apidoc.router')) == 'dingo';
        if ($usingDingoRouter) {
            $routes = $this->routeMatcher->getDingoRoutesToBeDocumented(config('apidoc.routes'));
        } else {
            $routes = $this->routeMatcher->getLaravelRoutesToBeDocumented(config('apidoc.routes'));
        }

        $generator = new Generator(config('apidoc.faker_seed'));
        $this->parsedRoutes = $this->processRoutes($generator, $routes);
        $this->parsedRoutes = collect($this->parsedRoutes)->groupBy('group')
            ->sortBy(static function ($group) {
                /* @var $group Collection */
                return $group->first()['group'];
            }, SORT_NATURAL);

        $this->writeMarkdown();
    }

    /**
     * Get route output using the given blade template
     *
     * @param string $view Name of blade template to be given to view()
     * @return void
     */
    public function getParsedRouteOutput($view = 'apidoc::partials.route') {
        $settings = $this->settings;

        return $this->parsedRoutes->map(function ($routeGroup) use ($settings, $view) {
            return $routeGroup->map(function ($route) use ($settings, $view) {
                if (count($route['cleanBodyParameters']) && ! isset($route['headers']['Content-Type'])) {
                    $route['headers']['Content-Type'] = 'application/json';
                }

                $route['output'] = (string) view($view)
                    ->with('route', $route)
                    ->with('settings', $settings)
                    ->render();

                return $route;
            });
        });
    }

    /**
     * Main command method
     *
     * @return void
     */
    private function writeMarkdown()
    {
        $outputPath = config('apidoc.output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'index.md';
        $compareFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'.compare.md';
        $prependFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'prepend.md';
        $appendFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'append.md';

        $this->infoText = view('apidoc::partials.info')
            ->with('outputPath', ltrim($outputPath, 'public/'))
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection());
        $this->settings = ['languages' => config('apidoc.example_languages')];


        $this->frontmatter = view('apidoc::partials.frontmatter')
            ->with('settings', $this->settings);
        $this->prependFileContents = file_exists($prependFile)
            ? file_get_contents($prependFile)."\n" : '';
        $this->appendFileContents = file_exists($appendFile)
            ? "\n".file_get_contents($appendFile) : '';

        /**
         * Don't want any sort of comparison for Vuepress (yet) so we're calling this
         * here to prevent any further modification to the route output etc
         */
        if (config('apidoc.vuepress.enabled')) {
            // Use vuepress.route view to get output
            $this->writeVuepressMarkdown($this->getParsedRouteOutput('apidoc::vuepress.route'));
        }

        $parsedRouteOutput = $this->getParsedRouteOutput();

        /*
         * In case the target file already exists, we should check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            if (preg_match('/---(.*)---\\s<!-- START_INFO -->/is', $generatedDocumentation, $generatedFrontmatter)) {
                $this->frontmatter = trim($generatedFrontmatter[1], "\n");
            }

            $parsedRouteOutput->transform(function ($routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function ($route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $generatedDocumentation, $existingRouteDoc)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_'.$route['id'].' -->(.*)<!-- END_'.$route['id'].' -->/is', $compareDocumentation, $lastDocWeGeneratedForThisRoute) && $lastDocWeGeneratedForThisRoute[1] !== $existingRouteDoc[1]);
                        if ($routeDocumentationChanged === false || $this->option('force')) {
                            if ($routeDocumentationChanged) {
                                $this->warn('Discarded manual changes for route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            }
                        } else {
                            $this->warn('Skipping modified route ['.implode(',', $route['methods']).'] '.$route['uri']);
                            $route['modified_output'] = $existingRouteDoc[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $this->writeDocumentarianMarkdown($parsedRouteOutput);
        $this->writeCompareMarkdown($parsedRouteOutput);

        if ($logo = config('apidoc.logo')) {
            copy(
                $logo,
                $outputPath.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'logo.png'
            );
        }
    }

    public function writeDocumentarianMarkdown($parsedRouteOutput) {
        $documentarian = new Documentarian();
        $outputPath = config('apidoc.output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'index.md';

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $this->frontmatter)
            ->with('infoText', $this->infoText)
            ->with('prependMd', $this->prependFileContents)
            ->with('appendMd', $this->appendFileContents)
            ->with('outputPath', $outputPath)
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection())
            ->with('parsedRoutes', $parsedRouteOutput);

        if (! is_dir($outputPath)) {
            $documentarian->create($outputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);
        $this->info("Wrote index.md to: $outputPath");

        $this->info('Generating API HTML code');

        $documentarian->generate($outputPath);

        $this->info("Wrote HTML documentation to: $outputPath/index.html");

        $this->writePostmanCollection($outputPath);
    }

    public function writeCompareMarkdown($parsedRouteOutput) {
        $outputPath = config('apidoc.output');
        $targetFile = $outputPath.DIRECTORY_SEPARATOR.'source'.DIRECTORY_SEPARATOR.'.compare.md';

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $this->frontmatter)
            ->with('infoText', $this->infoText)
            ->with('prependMd', $this->prependFileContents)
            ->with('appendMd', $this->appendFileContents)
            ->with('outputPath', $outputPath)
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection())
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($targetFile, $markdown);
        $this->info("Wrote compare file to: $outputPath");
    }

    /**
     * @param  Collection $parsedRoutes
     *
     * @return void
     */
    public function writeVuepressMarkdown($parsedRouteOutput) {
        $outputPath = trim(config('apidoc.vuepress.output'), '/\\') . DIRECTORY_SEPARATOR . trim(config('apidoc.vuepress.folder'), '/\\');
        $outputFile = $outputPath . DIRECTORY_SEPARATOR . 'index.md';
        $sourcePath = trim(config('apidoc.vuepress.output', '/\\') . DIRECTORY_SEPARATOR . 'source');
        $frontmatter = view('apidoc::vuepress.frontmatter')->with('settings', $this->settings);

        // Make vuepress folder specified in config if it doesn't exist
        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0777, true);
        }

        // Export the parsed routes if debug is enabled
        if (config('apidoc.vuepress.debug')) {
            if (!is_dir($sourcePath)) {
                mkdir($sourcePath, 0777, true);
            }

            file_put_contents($sourcePath . DIRECTORY_SEPARATOR . 'routes.js', $parsedRouteOutput);
            $this->info("[Vuepress] Wrote routes.js to $sourcePath");
        }

        $markdown = view('apidoc::vuepress.index')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $this->infoText)
            ->with('prependMd', $this->prependFileContents)
            ->with('appendMd', $this->appendFileContents)
            ->with('outputPath', $outputPath)
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection())
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($outputFile, $markdown);

        $this->info("[Vuepress] Wrote index.md to: $outputPath");

        if (config('apidoc.vuepress.single-page') == false) {
            $count = 1;

            foreach($parsedRouteOutput as $group => $routes) {
                /**
                 * By default, $group also contains the group description under the same key,
                 * use regex below to strip anything after a newline character
                 */
                $groupName = preg_match('/^(.+?(?=(\\n|\n|$)))/', $group, $groupNames);
                $groupName = $groupNames[0];

                // Hyphenate and lowercase $groupName and assign .md extension
                $fileName = strtolower(str_replace([' ', '_', '-'], '-', $groupName)) . '.md';
                $markdown = view('apidoc::vuepress.single-page')
                    ->with('writeCompareFile', false)
                    ->with('frontmatter', $frontmatter)
                    ->with('infoText', $this->infoText)
                    ->with('prependMd', $this->prependFileContents)
                    ->with('appendMd', $this->appendFileContents)
                    ->with('outputPath', $outputPath)
                    ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection())
                    ->with('parsedRoutes', [$group => $routes]);

                file_put_contents($outputPath . DIRECTORY_SEPARATOR . $fileName, $markdown);

                $this->info("[Vuepress] Wrote $fileName to: $outputPath");
                $count++;
            }

            $this->info("[Vuepress] Wrote $count markdown files to your Vuepress directory!");
            $this->warn("[Vuepress] Please ensure you update your .vuepress/config.js sidebar routes manually");
        }

        $this->writePostmanCollection($outputPath);
    }

    public function writePostmanCollection($outputPath) {
        if ($this->shouldGeneratePostmanCollection()) {
            $this->info("Generating Postman collection in $outputPath");

            file_put_contents($outputPath.DIRECTORY_SEPARATOR.'collection.json', $this->generatePostmanCollection($this->parsedRoutes));
        }
    }

    /**
     * @param Generator $generator
     * @param array $routes
     *
     * @return array
     */
    private function processRoutes(Generator $generator, array $routes)
    {
        $parsedRoutes = [];
        foreach ($routes as $routeItem) {
            $route = $routeItem['route'];
            /** @var Route $route */
            if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                $parsedRoutes[] = $generator->processRoute($route, $routeItem['apply']);
                $this->info('Processed route: ['.implode(',', $generator->getMethods($route)).'] '.$generator->getUri($route));
            } else {
                $this->warn('Skipping route: ['.implode(',', $generator->getMethods($route)).'] '.$generator->getUri($route));
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isValidRoute(Route $route)
    {
        return ! is_callable($route->getAction()['uses']) && ! is_null($route->getAction()['uses']);
    }

    /**
     * @param $route
     *
     * @throws ReflectionException
     *
     * @return bool
     */
    private function isRouteVisibleForDocumentation($route)
    {
        list($class, $method) = explode('@', $route);
        $reflection = new ReflectionClass($class);

        if (! $reflection->hasMethod($method)) {
            return false;
        }

        $comment = $reflection->getMethod($method)->getDocComment();

        if ($comment) {
            $phpdoc = new DocBlock($comment);

            return collect($phpdoc->getTags())
                ->filter(function ($tag) use ($route) {
                    return $tag->getName() === 'hideFromAPIDocumentation';
                })
                ->isEmpty();
        }

        return true;
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    private function generatePostmanCollection(Collection $routes)
    {
        $writer = new CollectionWriter($routes);

        return $writer->getCollection();
    }

    /**
     * Checks config if it should generate Postman collection.
     *
     * @return bool
     */
    private function shouldGeneratePostmanCollection()
    {
        return config('apidoc.postman.enabled', is_bool(config('apidoc.postman')) ? config('apidoc.postman') : false);
    }
}
