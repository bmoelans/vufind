<?php
/**
 * Record loader
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2010.
 * Copyright (C) The National Library of Finland 2015.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Record
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
namespace VuFind\Record;

use VuFind\Exception\RecordMissing as RecordMissingException;
use VuFind\Record\FallbackLoader\PluginManager as FallbackLoader;
use VuFind\RecordDriver\PluginManager as RecordFactory;
use VuFindSearch\Service as SearchService;

/**
 * Record loader
 *
 * @category VuFind
 * @package  Record
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Site
 */
class Loader implements \Zend\Log\LoggerAwareInterface
{
    use \VuFind\Log\LoggerAwareTrait;

    /**
     * Record factory
     *
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * Search service
     *
     * @var SearchService
     */
    protected $searchService;

    /**
     * Record cache
     *
     * @var Cache
     */
    protected $recordCache;

    /**
     * Fallback record loader
     *
     * @var FallbackLoader
     */
    protected $fallbackLoader;

    /**
     * Constructor
     *
     * @param SearchService  $searchService  Search service
     * @param RecordFactory  $recordFactory  Record loader
     * @param Cache          $recordCache    Record Cache
     * @param FallbackLoader $fallbackLoader Fallback record loader
     */
    public function __construct(SearchService $searchService,
        RecordFactory $recordFactory, Cache $recordCache = null,
        FallbackLoader $fallbackLoader = null
    ) {
        $this->searchService = $searchService;
        $this->recordFactory = $recordFactory;
        $this->recordCache = $recordCache;
        $this->fallbackLoader = $fallbackLoader;
    }

    /**
     * Given an ID and record source, load the requested record object.
     *
     * @param string $id              Record ID
     * @param string $source          Record source
     * @param bool   $tolerateMissing Should we load a "Missing" placeholder
     * instead of throwing an exception if the record cannot be found?
     *
     * @throws \Exception
     * @return \VuFind\RecordDriver\AbstractBase
     */
    public function load($id, $source = DEFAULT_SEARCH_BACKEND,
        $tolerateMissing = false
    ) {
        if (null !== $id && '' !== $id) {
            $results = [];
            if (null !== $this->recordCache
                && $this->recordCache->isPrimary($source)
            ) {
                $results = $this->recordCache->lookup($id, $source);
            }
            if (empty($results)) {
                $results = $this->searchService->retrieve($source, $id)
                    ->getRecords();
            }
            if (empty($results) && null !== $this->recordCache
                && $this->recordCache->isFallback($source)
            ) {
                $results = $this->recordCache->lookup($id, $source);
            }

            if (!empty($results)) {
                return $results[0];
            }
        }
        if ($tolerateMissing) {
            $record = $this->recordFactory->get('Missing');
            $record->setRawData(['id' => $id]);
            $record->setSourceIdentifier($source);
            return $record;
        }
        throw new RecordMissingException(
            'Record ' . $source . ':' . $id . ' does not exist.'
        );
    }

    /**
     * Given an array of IDs and a record source, load a batch of records for
     * that source.
     *
     * @param array  $ids                       Record IDs
     * @param string $source                    Record source
     * @param bool   $tolerateBackendExceptions Whether to tolerate backend
     * exceptions that may be caused by e.g. connection issues or changes in
     * subcscriptions
     *
     * @throws \Exception
     * @return array
     */
    public function loadBatchForSource($ids, $source = DEFAULT_SEARCH_BACKEND,
        $tolerateBackendExceptions = false
    ) {
        $cachedRecords = [];
        if (null !== $this->recordCache && $this->recordCache->isPrimary($source)) {
            // Try to load records from cache if source is cachable
            $cachedRecords = $this->recordCache->lookupBatch($ids, $source);
            // Check which records could not be loaded from the record cache
            foreach ($cachedRecords as $cachedRecord) {
                $key = array_search($cachedRecord->getUniqueId(), $ids);
                if ($key !== false) {
                    unset($ids[$key]);
                }
            }
        }

        // Try to load the uncached records from the original $source
        $genuineRecords = [];
        if (!empty($ids)) {
            try {
                $genuineRecords = $this->searchService->retrieveBatch($source, $ids)
                    ->getRecords();
            } catch (\VuFindSearch\Backend\Exception\BackendException $e) {
                if (!$tolerateBackendExceptions) {
                    throw $e;
                }
                $this->logWarning(
                    "Exception when trying to retrieve records from $source: "
                    . $e->getMessage()
                );
            }

            foreach ($genuineRecords as $genuineRecord) {
                $key = array_search($genuineRecord->getUniqueId(), $ids);
                if ($key !== false) {
                    unset($ids[$key]);
                }
            }
        }

        $retVal = $genuineRecords;
        if (!empty($ids) && $this->fallbackLoader
            && $this->fallbackLoader->has($source)
        ) {
            foreach ($this->fallbackLoader->get($source)->load($ids) as $record) {
                $retVal[] = $record;
                $key = array_search($record->getUniqueId(), $ids);
                if ($key !== false) {
                    unset($ids[$key]);
                } elseif ($oldId = $record->tryMethod('getPreviousUniqueId')) {
                    $key2 = array_search($record->getUniqueId(), $ids);
                    if ($key2 !== false) {
                        unset($ids[$key2]);
                    }
                }
            }
        }

        if (!empty($ids) && null !== $this->recordCache
            && $this->recordCache->isFallback($source)
        ) {
            // Try to load missing records from cache if source is cachable
            $cachedRecords = $this->recordCache->lookupBatch($ids, $source);
        }

        // Merge records found in cache and records loaded from original $source
        foreach ($cachedRecords as $cachedRecord) {
            $retVal[] = $cachedRecord;
        }

        return $retVal;
    }

    /**
     * Build a "missing record" driver.
     *
     * @param array $details Associative array of record details (from an IdList)
     *
     * @return \VuFind\RecordDriver\Missing
     */
    protected function buildMissingRecord($details)
    {
        $fields = $details['extra_fields'] ?? [];
        $fields['id'] = $details['id'];
        $record = $this->recordFactory->get('Missing');
        $record->setRawData($fields);
        $record->setSourceIdentifier($details['source']);
        return $record;
    }

    /**
     * Given an array of associative arrays with id and source keys (or pipe-
     * separated source|id strings), load all of the requested records in the
     * requested order.
     *
     * @param array $ids                       Array of associative arrays with
     * id/source keys or strings in source|id format.  In associative array formats,
     * there is also an optional "extra_fields" key which can be used to pass in data
     * formatted as if it belongs to the Solr schema; this is used to create
     * a mock driver object if the real data source is unavailable.
     * @param bool  $tolerateBackendExceptions Whether to tolerate backend
     * exceptions that may be caused by e.g. connection issues or changes in
     * subcscriptions
     *
     * @throws \Exception
     * @return array     Array of record drivers
     */
    public function loadBatch($ids, $tolerateBackendExceptions = false)
    {
        // Sort the IDs by source -- we'll create an associative array indexed by
        // source and record ID which points to the desired position of the indexed
        // record in the final return array:
        $idList = new IdList($ids);

        // Retrieve the records and put them back in order:
        $retVal = [];
        foreach ($idList->getIdsBySource() as $source => $currentIds) {
            $records = $this->loadBatchForSource(
                $currentIds, $source, $tolerateBackendExceptions
            );
            foreach ($records as $current) {
                $position = $idList->getRecordPosition($current);
                if ($position !== false) {
                    $retVal[$position] = $current;
                }
            }
        }

        // Check for missing records and fill gaps with \VuFind\RecordDriver\Missing
        // objects:
        foreach ($idList->getAll() as $i => $details) {
            if (!isset($retVal[$i]) || !is_object($retVal[$i])) {
                $retVal[$i] = $this->buildMissingRecord($details);
            }
        }

        // Send back the final array, with the keys in proper order:
        ksort($retVal);
        return $retVal;
    }

    /**
     * Set the context to control cache behavior
     *
     * @param string $context Cache context
     *
     * @return void
     */
    public function setCacheContext($context)
    {
        if (null !== $this->recordCache) {
            $this->recordCache->setContext($context);
        }
    }
}
