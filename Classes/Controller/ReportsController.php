<?php
/*
 * This file is part of the <My.Vendor> package.
 *
 * (c) 2022 Sebastian Helzle <sebastian@helzle.it>
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\Reports\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Eel\EelEvaluatorInterface;
use Neos\Eel\Exception as EelException;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Eel\Utility;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\I18n\EelHelper\TranslationParameterToken;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\LinkingService;
use Psr\Http\Message\UriInterface;
use Shuchkin\SimpleXLSXGen;

/**
 * @Flow\Scope("singleton")
 */
class ReportsController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @Flow\InjectConfiguration
     * @var array
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class
    ];

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var EelEvaluatorInterface
     */
    protected $eelEvaluator;

    /**
     * @var UriInterface
     */
    protected $baseUri;

    /**
     * @Flow\InjectConfiguration(package="Neos.Fusion", path="defaultContext")
     * @var array
     */
    protected $fusionDefaultEelContext;

    public function indexAction(): void
    {
        $presets = $this->getPresets();

        $this->view->assignMultiple([
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
            'presets' => $presets,
            'startDate' => '',
            'endDate' => '',
            // TODO: Allow configurable default dates
            //'startDate' => (new \DateTime())->sub(new \DateInterval('P1Y'))->format($this->settings['dateTimeFormats']['input']),
            //'endDate' => (new \DateTime())->format($this->settings['dateTimeFormats']['input']),
        ]);
    }

    /**
     * Generates a XLSX file from the given timeframe and nodedata
     *
     * @throws NodeException|EelException|StopActionException|HttpException|NodeTypeNotFoundException
     */
    public function generateReportAction(string $presetName, string $startDate = null, string $endDate = null): void
    {
        $preset = $this->getPreset($presetName);
        $startDateTime = $startDate ? \DateTime::createFromFormat($preset['dateTimeFormats']['input'], $startDate) : null;
        $endDateTime = $endDate ? \DateTime::createFromFormat($preset['dateTimeFormats']['input'], $endDate) : null;

        $context = $this->contextFactory->create();
        $startingPoint = $preset['startingPoint'] ? $context->getNode($preset['startingPoint']) : $context->getRootNode();

        if (!$startingPoint) {
            throw new \InvalidArgumentException(sprintf('Starting point "%s" not found', $preset['startingPoint']), 1654781654);
        }

        $nodeTypes = $preset['nodeTypes'];
        $nodeTypeFilter = implode(',', array_map(static fn($nodeType) => '[instanceof ' . $nodeType . ']', array_keys($nodeTypes)));

        // Find all nodes matching the configured node types
        $query = (new FlowQuery([$startingPoint]))->find($nodeTypeFilter);
        $data = array_reduce($query->get(), function (array $carry, NodeInterface $node) use ($nodeTypes, $startDateTime, $endDateTime, $preset) {
            if (($startDateTime && $node->getCreationDateTime() < $startDateTime) ||
                ($endDateTime && $node->getCreationDateTime() > $endDateTime)) {
                return $carry;
            }

            $nodeType = $node->getNodeType();
            $nodeTypeName = $nodeType->getName();
            if (!isset($nodeTypes[$nodeTypeName])) {
                foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
                    if (isset($nodeTypes[$superType->getName()])) {
                        $nodeTypeName = $superType->getName();
                        break;
                    }
                }
            }

            // Read each property of the node and add it to the data array
            // Converts datetime and asset properties to a human-readable format
            $nodeData = array_reduce($nodeTypes[$nodeTypeName]['properties'], function ($carry, $propertyName) use ($node, $preset) {
                $carry[$propertyName] = $this->getProperty($node, $propertyName, $preset);
                return $carry;
            }, []);

            // Evaluate each expression and the result add to the data array
            if (isset($nodeTypes[$nodeTypeName]['expressions'])) {
                foreach ($nodeTypes[$nodeTypeName]['expressions'] as $propertyName => $expression) {
                    $nodeData[$propertyName] = $this->resolveQuery($node, $expression);
                }
            }

            // Add link to the closest document node of the node
            /** @var NodeInterface $documentNode */
            $documentNode = (new FlowQuery([$node]))->closest('[instanceof Neos.Neos:Document]')->get(0);
            $nodeData['linkToContent'] = $this->getNodeUri($documentNode);

            $carry[$nodeTypeName][] = $nodeData;
            return $carry;
        }, []);

        $xlsx = new SimpleXLSXGen();
        foreach ($data as $nodeTypeName => $nodeData) {
            $sorting = $nodeTypes[$nodeTypeName]['sortBy'];
            if ($sorting) {
                uasort($nodeData, static function ($a, $b) use ($sorting) {
                    $valueA = $a[$sorting] ?? '';
                    $valueB = $b[$sorting] ?? '';
                    if ($valueA === $valueB) {
                        return 0;
                    }
                    return $valueA <=> $valueB;
                });
            }

            // Generate table header
            $headerRow = array_map(function ($propertyName) use ($nodeTypeName) {
                $readablePropertyName = $this->nodeTypeManager->getNodeType($nodeTypeName)->getProperties()[$propertyName]['ui']['label'] ?? $propertyName;
                return $this->translateByShortHandString($readablePropertyName);
            }, $nodeTypes[$nodeTypeName]['properties']);

            if (isset($nodeTypes[$nodeTypeName]['expressions'])) {
                $headerRow = array_merge($headerRow, array_keys($nodeTypes[$nodeTypeName]['expressions']));
            }

            $headerRow[]= $this->translateByShortHandString('Shel.Neos.Reports:Module:column.linkToContent');
            array_unshift($nodeData, $headerRow);

            $xlsx->addSheet($nodeData, $this->nodeTypeManager->getNodeType($nodeTypeName)->getLabel());
        }

        $filenameParts = [
            $preset['filenamePrefix'],
            (new \DateTime())->format($preset['dateTimeFormats']['filename']),
        ];
        if ($startDateTime) {
            $filenameParts[] = 'from-' . $startDateTime->format($preset['dateTimeFormats']['filename']);
        }
        if ($endDateTime) {
            $filenameParts[] = 'to-' . $endDateTime->format($preset['dateTimeFormats']['filename']);
        }

        $xlsx->downloadAs(implode('-', $filenameParts) . '.xlsx');
        exit();
    }

    /**
     * @throws NodeException|HttpException
     */
    protected function getProperty(NodeInterface $node, string $propertyName, array $preset): string
    {
        $propertyValue = $node->getProperty($propertyName) ?? '';

        if ($propertyValue instanceof \DateTime) {
            $propertyConfig = $node->getNodeType()->getProperties()[$propertyName] ?? [];
            $propertyFormat = $propertyConfig['ui']['inspector']['editorOptions']['format'] ?? $preset['dateTimeFormats']['values'];
            return $propertyValue->format($propertyFormat);
        }

        if ($propertyValue instanceof AssetInterface) {
            $propertyValue = $this->resourceManager->getPublicPersistentResourceUri($propertyValue->getResource());

            // Make sure we have a full uri
            if ($propertyValue[0] === '/') {
                $propertyValue = $this->getBaseUri() . substr($propertyValue, 1);
            }
            return $propertyValue;
        }

        if ($propertyValue instanceof NodeInterface) {
            return $propertyValue->getLabel();
        }

        return (string)$propertyValue;
    }

    protected function getNodeUri(?NodeInterface $node): string
    {
        if (!$node || $node->isHidden() || $node->isRemoved() || $node->isHiddenInIndex()) {
            return '';
        }
        return $this->linkingService->createNodeUri(
            $this->getControllerContext(),
            $node,
            $node,
            'html',
            true
        );
    }

    protected function translateByShortHandString(string $shortHandString): ?string
    {
        $shortHandStringParts = explode(':', $shortHandString);
        if (count($shortHandStringParts) === 3) {
            [$package, $source, $id] = $shortHandStringParts;
            try {
                return $this->createTranslationParameterToken($id)
                    ->package($package)
                    ->source(str_replace('.', '/', $source))
                    ->translate();
            } catch (\Exception $e) {
            }
        }

        return $shortHandString;
    }

    protected function createTranslationParameterToken(string $id = null, string $originalLabel = null): TranslationParameterToken
    {
        return new TranslationParameterToken($id, $originalLabel);
    }

    /**
     * @throws HttpException
     */
    protected function getBaseUri(): UriInterface
    {
        if (!$this->baseUri) {
            $this->baseUri = $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest();
        }
        return $this->baseUri;
    }

    protected function resolveQuery(NodeInterface $node, string $expression): string
    {
        try {
            $result = (string)Utility::evaluateEelExpression(
                $expression,
                $this->eelEvaluator,
                ['node' => $node],
                $this->fusionDefaultEelContext
            );
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $result = $this->translateByShortHandString('Shel.Neos.Reports:Module:error.eelExpression');
        }
        return $result;
    }

    protected function getPresets(): array
    {
        return $this->settings['presets'] ?? [];
    }

    protected function getPreset(string $presetName): array
    {
        $presets = $this->getPresets();
        if (!isset($presets[$presetName])) {
            throw new \InvalidArgumentException('Preset "' . $presetName . '" does not exist');
        }
        return array_merge(
            $this->settings['defaults'],
            [
                'label' => $presetName,
                'filenamePrefix' => $presetName,
            ],
            $presets[$presetName]
        );
    }

}
