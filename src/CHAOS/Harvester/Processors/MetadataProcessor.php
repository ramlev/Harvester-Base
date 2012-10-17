<?php
namespace CHAOS\Harvester\Processors;

use CHAOS\Harvester\Shadows\MetadataShadow;
use CHAOS\Harvester\Shadows\ObjectShadow;
use \RuntimeException;

abstract class MetadataProcessor extends Processor {
	
	protected $_schemaSource;
	protected $_schemaGUID;
	
	public function fetchSchema($schemaGUID) {
		$this->_harvester->debug("Fetching schema: %s", $schemaGUID);
		$this->_schemaGUID = $schemaGUID;
		
		$response = $this->_harvester->getChaosClient()->MetadataSchema()->Get($this->_schemaGUID);
		if(!$response->WasSuccess() || !$response->MCM()->WasSuccess() || $response->MCM()->Count() < 1) {
			throw new RuntimeException("Failed to fetch XML schemas from the Chaos system, for schema GUID '$this->_schemaGUID'.");
		}
		$schemas = $response->MCM()->Results();
		$this->_schemaSource = $schemas[0]->SchemaXML;
	}
	
	protected $_validate;
	
	public function setValidate($validate) {
		$this->_validate = $validate;
	}
	
	public function process($externalObject, $shadow = null) {
		$this->_harvester->debug(__CLASS__." is processing.");
		
		assert($shadow instanceof ObjectShadow);
	
		$metadata = new MetadataShadow();
		$metadata->metadataSchemaGUID = $this->_schemaGUID;
		$metadata->xml = $this->generateMetadata($externalObject, $shadow);
		if($this->_validate === true) {
			$dom = dom_import_simplexml($metadata->xml)->ownerDocument;
			$dom->formatOutput = true;
			if(!$dom->schemaValidateSource($this->_schemaSource)) {
				return $shadow;
			}
		}
		$shadow->metadataShadows[] = $metadata;
		return $shadow;
	}
	
	public abstract function generateMetadata($externalObject, $shadow = null);
	
}