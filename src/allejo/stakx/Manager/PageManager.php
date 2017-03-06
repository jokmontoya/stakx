<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\Manager;

use allejo\stakx\Exception\FileAwareException;
use allejo\stakx\Exception\TrackedItemNotFoundException;
use allejo\stakx\FrontMatter\ExpandedValue;
use allejo\stakx\Object\ContentItem;
use allejo\stakx\Object\DynamicPageView;
use allejo\stakx\Object\JailObject;
use allejo\stakx\Object\PageView;
use allejo\stakx\Object\RepeaterPageView;
use allejo\stakx\System\FileExplorer;
use allejo\stakx\System\Folder;
use Twig_Error_Syntax;
use Twig_Template;

/**
 * This class is responsible for handling all of the PageViews within a website.
 *
 * PageManager will parse all available dynamic and static PageViews. After, dynamic PageViews will be prepared by
 * setting the appropriate values for each ContentItem such as permalinks. Lastly, this class will compile all of the
 * PageViews and write them to the target directory.
 *
 * @package allejo\stakx\Manager
 */
class PageManager extends TrackingManager
{
    /**
     * The relative (to the stakx project) file path to the redirect template
     *
     * @var string|bool
     */
    private $redirectTemplate;

    /**
     * @var PageView[]
     */
    private $twigExtendsDeps;

    /**
     * @var ContentItem[][]
     */
    private $collections;

    /**
     * @var Folder
     */
    private $targetDir;

    /**
     * @var PageView[]
     */
    private $flatPages;

    /**
     * @var PageView[]
     */
    private $siteMenu;

    /**
     * @var array
     */
    private $twigOpts;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * PageManager constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->redirectTemplate = false;
        $this->twigExtendsDeps = array();
        $this->collections = array();
        $this->flatPages = array();
        $this->siteMenu = array();
    }

    /**
     * Give this manager the collections we'll be using for dynamic PageViews
     *
     * @param ContentItem[][] $collections
     */
    public function setCollections (&$collections)
    {
        $this->collections = &$collections;
    }

    /**
     * Set the template used for redirects
     *
     * @param false|string $filePath The path to the redirect template
     */
    public function setRedirectTemplate ($filePath)
    {
        $this->redirectTemplate = $filePath;
    }

    /**
     * The location where the compiled website will be written to
     *
     * @param Folder $folder The relative target directory as specified from the configuration file
     */
    public function setTargetFolder (&$folder)
    {
        $this->targetDir = &$folder;
    }

    public function configureTwig ($configuration, $options)
    {
        $this->twigOpts['configuration'] = $configuration;
        $this->twigOpts['options']       = $options;

        $this->createTwigManager();
    }

    public function getFlatPages ()
    {
        return $this->flatPages;
    }

    /**
     * An array representing the website's menu structure with children and grandchildren made from static PageViews
     *
     * @return JailObject[]
     */
    public function getSiteMenu ()
    {
        $jailedMenu = array();

        foreach ($this->siteMenu as $key => $value)
        {
            // If it's an array, it means the parent is hidden from the site menu therefore its children should be too
            if (is_array($this->siteMenu[$key]))
            {
                continue;
            }

            $jailedMenu[$key] = $value->createJail();
        }

        return $jailedMenu;
    }

    /**
     * Go through all of the PageView directories and create a respective PageView for each and classify them as a
     * dynamic or static PageView.
     *
     * @param $pageViewFolders
     */
    public function parsePageViews ($pageViewFolders)
    {
        if (empty($pageViewFolders)) { return; }

        /**
         * The name of the folder where PageViews are located
         *
         * @var $pageViewFolder string
         */
        foreach ($pageViewFolders as $pageViewFolderName)
        {
            $pageViewFolder = $this->fs->absolutePath($pageViewFolderName);

            if (!$this->fs->exists($pageViewFolder))
            {
                continue;
            }

            $this->scanTrackableItems($pageViewFolder, array(
                'fileExplorer' => FileExplorer::INCLUDE_ONLY_FILES
            ), array('/.html$/', '/.twig$/'));
            $this->saveFolderDefinition($pageViewFolderName);
        }
    }

    /**
     * Compile dynamic and static PageViews
     */
    public function compileAll ()
    {
        foreach (array_keys($this->trackedItemsFlattened) as $filePath)
        {
            $this->compileFromFilePath($filePath);
        }
    }

    public function compileSome ($filter = array())
    {
        /** @var PageView $pageView */
        foreach ($this->trackedItemsFlattened as $pageView)
        {
            if ($pageView->hasTwigDependency($filter['namespace'], $filter['dependency']))
            {
                $this->compilePageView($pageView);
            }
        }
    }

    /**
     * @param ContentItem $contentItem
     */
    public function compileContentItem (&$contentItem)
    {
        $pageView = $contentItem->getPageView();

        // This ContentItem doesn't have an individual PageView dedicated to displaying this item
        if (is_null($pageView))
        {
            return;
        }

        $template = $this->createTemplate($pageView);
        $contentItem->evaluateFrontMatter(
            $pageView->getFrontMatter(false)
        );

        $output = $template->render(array(
            'this' => $contentItem
        ));

        $this->targetDir->writeFile($contentItem->getTargetFile(), $output);
    }

    /**
     * Add a new ContentItem to the respective parent PageView of the ContentItem
     *
     * @param ContentItem $contentItem
     */
    public function updatePageView ($contentItem)
    {
        /** @var DynamicPageView $pageView */
        foreach ($this->trackedItems['dynamic'] as &$pageView)
        {
            $fm = $pageView->getFrontMatter(false);

            if ($fm['collection'] == $contentItem->getCollection())
            {
                $pageView->addContentItem($contentItem);
            }
        }
    }

    /**
     * Update an existing Twig variable that's injected globally
     *
     * @param string $variable
     * @param string $value
     */
    public function updateTwigVariable ($variable, $value)
    {
        $this->twig->addGlobal($variable, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function isTracked($filePath)
    {
        return (parent::isTracked($filePath) || isset($this->twigExtendsDeps[$filePath]));
    }

    /**
     * {@inheritdoc}
     */
    public function refreshItem($filePath)
    {
        if (parent::isTracked($filePath))
        {
            $this->compileFromFilePath($filePath);

            return;
        }

        $this->createTwigManager();

        foreach ($this->twigExtendsDeps[$filePath] as $pageView)
        {
            $this->compilePageView($pageView);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function handleTrackableItem($filePath, $options = array())
    {
        $pageView  = PageView::create($filePath);
        $namespace = $pageView->getType();

        switch ($namespace)
        {
            case PageView::DYNAMIC_TYPE:
                $this->handleTrackableDynamicPageView($pageView);
                break;

            case PageView::STATIC_TYPE:
                $this->handleTrackableStaticPageView($pageView);
                break;

            default:
                break;
        }

        $this->addObjectToTracker($pageView, $pageView->getRelativeFilePath(), $namespace);
        $this->saveTrackerOptions($pageView->getRelativeFilePath(), array(
            'viewType' => $namespace
        ));
    }

    /**
     * @param DynamicPageView $pageView
     */
    private function handleTrackableDynamicPageView ($pageView)
    {
        $frontMatter = $pageView->getFrontMatter(false);
        $collection = $frontMatter['collection'];

        if (!isset($this->collections[$collection]))
        {
            throw new \RuntimeException("The '$collection' collection is not defined");
        }

        foreach ($this->collections[$collection] as &$item)
        {
            $item->evaluateFrontMatter($frontMatter);
            $pageView->addContentItem($item);
        }
    }

    /**
     * @param PageView $pageView
     */
    private function handleTrackableStaticPageView ($pageView)
    {
        if (empty($pageView['title'])) { return; }

        $this->addToSiteMenu($pageView);
        $this->flatPages[$pageView['title']] = $pageView->createJail();
    }

    /**
     * Create a Twig environment
     */
    private function createTwigManager ()
    {
        $twig = new TwigManager();
        $twig->configureTwig(
            $this->twigOpts['configuration'],
            $this->twigOpts['options']
        );

        $this->twig = TwigManager::getInstance();
    }

    /**
     * Compile a given PageView
     *
     * @param string $filePath The file path to the PageView to compile
     *
     * @throws \Exception
     */
    private function compileFromFilePath ($filePath)
    {
        if (!$this->isTracked($filePath))
        {
            throw new TrackedItemNotFoundException('PageView not found');
        }

        /** @var DynamicPageView|PageView|RepeaterPageView $pageView */
        $pageView = &$this->trackedItemsFlattened[$filePath];

        try
        {
            $pageView->refreshFileContent();
            $this->compilePageView($pageView);
        }
        catch (\Exception $e)
        {
            throw FileAwareException::castException($e, $filePath);
        }
    }

    /**
     * @param DynamicPageView|RepeaterPageView|PageView $pageView
     */
    private function compilePageView ($pageView)
    {
        switch ($pageView->getType())
        {
            case PageView::REPEATER_TYPE:
                $this->compileRepeaterPageView($pageView);
                $this->compileExpandedRedirects($pageView);
                break;

            case PageView::DYNAMIC_TYPE:
                $this->compileDynamicPageView($pageView);
                $this->compileNormalRedirects($pageView);
                break;

            case PageView::STATIC_TYPE:
                $this->compileStaticPageView($pageView);
                $this->compileNormalRedirects($pageView);
                break;
        }
    }

    /**
     * @param RepeaterPageView $pageView
     */
    private function compileRepeaterPageView (&$pageView)
    {
        $template = $this->createTemplate($pageView);
        $pageView->rewindPermalink();

        foreach ($pageView->getRepeaterPermalinks() as $permalink)
        {
            $pageView->bumpPermalink();
            $pageView->setFrontMatter(array(
                'permalink' => $permalink->getEvaluated(),
                'iterators' => $permalink->getIterators()
            ));

            $output = $template->render(array(
                'this' => $pageView->createJail()
            ));

            $this->output->notice("Writing repeater file: {file}", array('file' => $pageView->getTargetFile()));
            $this->targetDir->writeFile($pageView->getTargetFile(), $output);
        }
    }

    /**
     * @param PageView $pageView
     */
    private function compileDynamicPageView (&$pageView)
    {
        $template = $this->createTemplate($pageView);

        $pageViewFrontMatter = $pageView->getFrontMatter(false);
        $collection = $pageViewFrontMatter['collection'];

        if (!isset($this->collections[$collection]))
        {
            throw new \RuntimeException("The '$collection' collection is not defined");
        }

        /** @var ContentItem $contentItem */
        foreach ($this->collections[$collection] as &$contentItem)
        {
            $output = $template->render(array(
                'this' => $contentItem->createJail()
            ));

            $this->output->notice("Writing file: {file}", array('file' => $contentItem->getTargetFile()));
            $this->targetDir->writeFile($contentItem->getTargetFile(), $output);
        }
    }

    /**
     * @param PageView $pageView
     */
    private function compileStaticPageView (&$pageView)
    {
        $this->twig->addGlobal('__currentTemplate', $pageView->getFilePath());

        $template = $this->createTemplate($pageView);
        $output = $template->render(array(
            'this' => $pageView->createJail()
        ));

        $this->output->notice("Writing file: {file}", array('file' => $pageView->getTargetFile()));
        $this->targetDir->writeFile($pageView->getTargetFile(), $output);
    }

    /**
     * @param DynamicPageView|PageView $pageView
     */
    private function compileNormalRedirects (&$pageView)
    {
        foreach ($pageView->getRedirects() as $redirect)
        {
            $redirectPageView = PageView::createRedirect(
                $redirect,
                $pageView->getPermalink(),
                $this->redirectTemplate
            );

            $this->compilePageView($redirectPageView);
        }
    }

    /**
     * @param RepeaterPageView $pageView
     */
    private function compileExpandedRedirects (&$pageView)
    {
        $permalinks = $pageView->getRepeaterPermalinks();

        /** @var ExpandedValue[] $repeaterRedirect */
        foreach ($pageView->getRepeaterRedirects() as $repeaterRedirect)
        {
            /**
             * @var int           $index
             * @var ExpandedValue $redirect
             */
            foreach ($repeaterRedirect as $index => $redirect)
            {
                $redirectPageView = PageView::createRedirect(
                    $redirect->getEvaluated(),
                    $permalinks[$index]->getEvaluated(),
                    $this->redirectTemplate
                );

                $this->compilePageView($redirectPageView);
            }
        }
    }

    /**
     * Add a static PageView to the menu array. Dynamic PageViews are not added to the menu
     *
     * @param PageView $pageView
     */
    private function addToSiteMenu (&$pageView)
    {
        $frontMatter = $pageView->getFrontMatter();

        if (isset($frontMatter['menu']) && !$frontMatter['menu'])
        {
            return;
        }

        $url = trim($pageView->getPermalink(), '/');

        if (empty($url))
        {
            return;
        }

        $root = &$this->siteMenu;
        $dirs = explode('/', $url);

        while (count($dirs) > 0)
        {
            $name = array_shift($dirs);
            $name = (!empty($name)) ? $name : '.';

            if (!is_null($name) && count($dirs) == 0)
            {
                if (isset($root[$name]) && is_array($root[$name]))
                {
                    $children = &$pageView->getChildren();
                    $children = $root[$name]['children'];
                }

                $root[$name] = &$pageView;
            }
            else
            {
                if (!isset($root[$name]['children']))
                {
                    $root[$name]['children'] = array();
                }

                $root = &$root[$name]['children'];
            }
        }
    }

    /**
     * @param PageView $pageView
     *
     * @return Twig_Template
     * @throws Twig_Error_Syntax
     */
    private function createTemplate (&$pageView)
    {
        try
        {
            $template = $this->twig->createTemplate($pageView->getContent());

            $this->trackParentTwigTemplate($template, $pageView);

            return $template;
        }
        catch (Twig_Error_Syntax $e)
        {
            $e->setTemplateLine($e->getTemplateLine() + $pageView->getLineOffset());
            $e->setTemplateName($pageView->getRelativeFilePath());

            throw $e;
        }
    }

    /**
     * Find the parent Twig templates of the given template and keep a list of it
     *
     * @param Twig_Template $template The template created from the PageView's content
     * @param PageView      $pageView The PageView that has this content. Used to keep a reference of PageViews
     */
    private function trackParentTwigTemplate ($template, &$pageView)
    {
        if (!$this->tracking) { return; }

        /** @var Twig_Template $parent */
        $parent = $template->getParent(array());

        while ($parent !== false)
        {
            $filePath = $this->fs->getRelativePath($parent->getSourceContext()->getPath());

            $this->twigExtendsDeps[$filePath][(string)$pageView->getFilePath()] = &$pageView;
            $parent = $parent->getParent(array());
        }
    }
}