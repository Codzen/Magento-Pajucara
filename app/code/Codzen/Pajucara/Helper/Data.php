<?php

/**
 * @category  Codzen
 * @package   Codzen_Pajucara
 * @author    Rodrigo Donini
 * @copyright 2016 Codzen (http://www.codzen.com.br)
 */
class Codzen_Pajucara_Helper_Data extends Mage_Core_Helper_Abstract
{
	public function enabledDebug()
	{
		return Mage::getStoreConfigFlag('carriers/pajucara/debug');
	}

	public function writeLog($obj)
	{
		if ($this->enabledDebug()) {
			if(is_string($obj)){
				Mage::log($obj, Zend_Log::DEBUG, 'codzen_pajucara.log', true);
			}else{
				Mage::log(var_export($obj, true), Zend_Log::DEBUG, 'codzen_pajucara.log', true);
			}
		}
	}

	public function getXML($parameters) {
		$xml_data = new SimpleXMLElement('<?xml version="1.0"?><prologwsvp></prologwsvp>');
		$this->arrayToXml($parameters,$xml_data);

		return $xml_data->asXML();
	}

	function arrayToXml( $data, &$xml_data ) {
		try {
			foreach( $data as $key => $value ) {
				if( is_array($value) ) {
					if( is_numeric($key) ){
						$key = 'item'.$key; 
					}
					$subnode = $xml_data->addChild($key);
					$this->arrayToXml($value, $subnode);
				} else {
					$xml_data->addChild("$key",htmlspecialchars("$value"));
				}
			}
		}
		catch( SoapFault $fault ){
			$this->writeLog($fault);
			return false;
		}
	}

}
