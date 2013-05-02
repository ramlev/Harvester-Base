<?php
namespace CHAOS\Harvester\Shadows;
use \RuntimeException;
class ObjectShadow extends Shadow {
	
	/**
	 * Number of objects to be considered duplicates.
	 * If more than this number of objects are returned from an object/get,
	 * none of them are considered duplicates as the query are way too
	 * ambiguous.
	 * @var integer
	 */
	const DUPLICATE_OBJECTS_THESHOLD = 3;
	
	/**
	 * Shadows of the related metadata.
	 * @var MetadataShadow[]
	 */
	public $metadataShadows = array();
	
	/**
	 * Shadows of the related files.
	 * @var FileShadow[]
	 */
	public $fileShadows = array();
	
	/**
	 * Shadows of the related objects.
	 * @var ObjectShadow[]
	 */
	public $relatedObjectShadows = array();
	
	public $folderId;
	
	public $objectTypeId;
	
	/**
	 * The query to execute to get the object from the service.
	 * @var string
	 */
	public $query;
	
	/**
	 * An associative array of extra information exchanged between the different processors
	 * building up the shadow.
	 * @var string[string]
	 */
	public $extras = array();
	
	/**
	 * An array of accesspoint GUIDs on which to publish the object if it should not be skipped.
	 * @var string[]
	 */
	public $publishAccesspointGUIDs = array();
	
	/**
	 * An array of accesspoint GUIDs on which to unpublish objects if it should be skipped.
	 * @var string[]
	 */
	public $unpublishAccesspointGUIDs = array();
	
	/**
	 * If the object is skipped, it is unpublished from any accesspoint.
	 * @var boolean
	 */
	public $unpublishEverywhere;
	
	/**
	 * The chaos object from the service.
	 * @var \stdClass Chaos object.
	 */
	protected $object;
	
	/**
	 * These are the array of objects returned from the service, when the query is too ambiguous.
	 * @var \stdClass[] Chaos Objects.
	 */
	protected $duplicateObjects = array();
	
	public function commit($harvester, $parent = null) {
		$harvester->debug("Committing the shadow of an object.");
		if($parent != null) {
			throw new RuntimeException('Committing related objects has not yet been implemented.');
		}
		
		if($harvester->hasOption('no-shadow-commitment')) {
			$this->get($harvester, false);
			if($this->object) {
				$harvester->info("Because the 'no-shadow-commitment' runtime option is set, this object is not committed to CHAOS object '%s'.", $this->object->GUID);
				return $this->object;
			} else {
				$harvester->info("Because the 'no-shadow-commitment' runtime option is set, this object is created as a CHAOS object.");
				return;
			}
		}
		
		if($harvester->hasOption('require-files-on-objects') && count($this->fileShadows) == 0) {
			$harvester->info("Object shadow skipped because 'require-files-on-objects' is set and no file shadows was attached.");
			$this->skipped = true;
		}
		
		// Get or create the object, while saving it to the object itself.
		if($this->skipped) {
			// Get the chaos object, but do not create it if its not there.
			$this->get($harvester, false);
		} else {
			$this->get($harvester);
		
			foreach($this->metadataShadows as $metadataShadow) {
				assert($metadataShadow instanceof MetadataShadow);
				$metadataShadow->commit($harvester, $this);
			}
			
			$fileLine = "Committing files: ";
			foreach($this->fileShadows as $fileShadow) {
				$file = $fileShadow->commit($harvester, $this);
				if($file->status == 'reused') {
					$fileLine .= '.';
				} else if($file->status == 'created') {
					$fileLine .= '+';
				} else {
					$fileLine .= '?';
				}
			}
			
			$fileIDs = array();
			foreach($this->fileShadows as $fileShadow) {
				$fileIDs[] = $fileShadow->getFileID();
			}
			foreach($this->object->Files as $file) {
				if(!in_array($file->ID, $fileIDs)) {
					// This file is related to the object, but it has been removed.
					$harvester->debug("Deleting file #%u.", $file->ID);
					$fileLine .= '-';
					$harvester->getChaosClient()->File()->Delete($file->ID);
				}
			}
			
			/*
			// FIXME: Consider deleting unsued files, ie. files that is related to a reused CHAOS object but which are not in the shadows.
			if(count($this->object->Files) > count($this->fileShadows)) {
				$harvester->info("The reused CHAOS object has more files referenced than the object shadow has. But as the CHAOS client has not implemented a File/Delete call this cannot be completed.");
				
			}
			*/
			
			$harvester->info($fileLine);
			
			foreach($this->relatedObjectShadows as $relatedObjectShadow) {
				// TODO: Consider adding a list of committed object shadows to prevent cycles.
				$relatedObjectShadow->commit($harvester, $this);
			}
		}
		
		if($this->skipped !== true) {
			$this->publishObject($harvester, $this->object);
		} else {
			// Only do this if an object was returned from the query.
			if($this->object !== null) {
				$this->unpublishObject($harvester, $this->object);
			} else {
				$harvester->info("No need to unpublish as this external object is not represented in CHAOS.");
			}
		}

		// Unpublish any duplicate objects.
		foreach($this->duplicateObjects as $duplicateObject) {
			$this->unpublishObject($harvester, $duplicateObject);
		}
		
		// This is sat by the call to get.
		return $this->object;
	}
	
	/**
	 * Publish the object on the accesspoints given in the configuration.
	 * @param CHAOS\Harvester\ChaosHarvester $harvester The harvester used to publish object. Get the chaos client from this.
	 * @param \stdClass $object Chaos object to publish.
	 * @throws RuntimeException If an error occures while publishing.
	 */
	protected function publishObject($harvester, $object) {
		$start = new \DateTime();
		// Publish this as of yesterday - servertime issues.
		$aDayInterval = new \DateInterval("P1D");
		$start->sub($aDayInterval);
		
		foreach($this->publishAccesspointGUIDs as $accesspointGUID) {
			$harvester->info(sprintf("Publishing %s to accesspoint = %s with startDate = %s", $object->GUID, $accesspointGUID, $start->format("Y-m-d H:i:s")));
			$response = $harvester->getChaosClient()->Object()->SetPublishSettings($object->GUID, $accesspointGUID, $start);
			if(!$response->WasSuccess()) {
				throw new RuntimeException("Couldn't set publish settings: {$response->Error()->Message()}");
			}
			if(!$response->MCM()->WasSuccess()) {
				throw new RuntimeException("Couldn't set publish settings: (MCM) {$response->MCM()->Error()->Message()}");
			}
		}
	}
	
	/**
	 * Publish the object on the accesspoints given in the configuration.
	 * @param CHAOS\Harvester\ChaosHarvester $harvester The harvester used to publish object. Get the chaos client from this.
	 * @param \stdClass $object Chaos object to publish.
	 * @throws RuntimeException If an error occures while publishing.
	 */
	protected function unpublishObject($harvester, $object) {
		$unpublishAccesspointGUIDs = array();
		
		// If unpublish everywhere is set, loop through the accesspoints assoiciated with the object.
		if($this->unpublishEverywhere) {
			foreach($object->AccessPoints as $accesspoint) {
				$unpublishAccesspointGUIDs[] = $accesspoint->AccessPointGUID;
			}
		}
		
		// Add the access point guids from the configuration file.
		$unpublishAccesspointGUIDs = array_merge($unpublishAccesspointGUIDs, $this->unpublishAccesspointGUIDs);
		
		foreach($unpublishAccesspointGUIDs as $accesspointGUID) {
			$harvester->info(sprintf("Unpublishing %s from accesspoint = %s", $object->GUID, $accesspointGUID));
			$response = $harvester->getChaosClient()->Object()->SetPublishSettings($object->GUID, $accesspointGUID);
			if(!$response->WasSuccess()) {
				throw new RuntimeException("Couldn't set publish settings: {$response->Error()->Message()}");
			}
			if(!$response->MCM()->WasSuccess()) {
				throw new RuntimeException("Couldn't set publish settings: (MCM) {$response->MCM()->Error()->Message()}");
			}
		}
	}
	
	/**
	 * Get or create the object shadow.
	 * @param CHAOS\Harvester\ChaosHarvester $harvester
	 */
	public function get($harvester, $orCreate = true) {
		if($this->object != null) {
			return $this->object;
		}
		
		$this->duplicateObjects = array();
		
		$chaos = $harvester->getChaosClient();
		
		$harvester->debug("Trying to get the CHAOS object from ".$this->query);
		$response = $chaos->Object()->Get($this->query, 'DateCreated+asc', null, 0, self::DUPLICATE_OBJECTS_THESHOLD+1, true, true, true, true);
		if(!$response->WasSuccess()) {
			throw new RuntimeException("General error when getting the object from the chaos service: " . $response->Error()->Message());
		} elseif(!$response->MCM()->WasSuccess()) {
			throw new RuntimeException("MCM error when getting the object from the chaos service: " . $response->MCM()->Error()->Message());
		}
		
		$object = null;
		if($response->MCM()->TotalCount() == 0) {
			if($orCreate) {
				$response = $chaos->Object()->Create($this->objectTypeId, $this->folderId);
				if(!$response->WasSuccess()) {
					throw new RuntimeException("General error when creating the object in the chaos service: " . $response->Error()->Message());
				} elseif(!$response->MCM()->WasSuccess()) {
					throw new RuntimeException("MCM error when creating the object in the chaos service: " . $response->MCM()->Error()->Message());
				}
				if($response->MCM()->TotalCount() == 1) {
					$results = $response->MCM()->Results();
					$object = $results[0];
					$harvester->info("Created a new object in the service with GUID = %s.", $object->GUID);
				} else {
					throw new RuntimeException("The service didn't respond with a single object when creating it.");
				}
			} else {
				return null;
			}
		} else {
			if($response->MCM()->TotalCount() > 1) {
				trigger_error('The query specified when getting an object resulted in '.$response->MCM()->TotalCount().' objects. Consider if the query should be more specific.', E_USER_WARNING);
				if($response->MCM()->TotalCount()-1 > self::DUPLICATE_OBJECTS_THESHOLD) {
					throw new \RuntimeException(strval($response->MCM()->TotalCount()-1)." duplicate objects, is too many (> ".strval(self::DUPLICATE_OBJECTS_THESHOLD)."). The query is way too ambiguous.");
				}
			}
			$results = $response->MCM()->Results();
			$object = $results[0];
			foreach($results as $duplicateObject) {
				if($duplicateObject != $object) {
					$this->duplicateObjects[] = $duplicateObject;
				}
			}
			$dateCreated = $object->DateCreated;
			$harvester->info("Reusing object from service, created %s with GUID = %s.", date('r', $dateCreated), $object->GUID);
		}
		
		$this->object = $object;
		return $this->object;
	}
	
	/**
	 * Generates a string representation of the object shadow.
	 * @return string A string representation of the object shadow.
	 */
	public function __toString() {
		if($this->object != null && strlen($this->object->GUID) > 0) {
			return strval($this->object->GUID);
		} elseif(strlen($this->query) > 0) {
			return "[chaos object found from {$this->query}]";
		} else {
			return '';
		}
	}
}