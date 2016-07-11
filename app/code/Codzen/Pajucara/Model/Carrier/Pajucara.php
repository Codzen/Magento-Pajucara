<?php

/**
 * @category  Codzen
 * @package   Codzen_Pajucara
 * @author    Rodrigo Donini
 * @copyright 2016 Codzen (http://www.codzen.com.br)
 */
class Codzen_Pajucara_Model_Carrier_Pajucara
    extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{
	protected $_code            = 'codzen_pajucara';
	protected $_fromZip         = null;
	protected $_toZip           = null;
	protected $_totalPrice      = null;
	protected $_msgErro         = null;
	protected $_result          = null;

	/**
	* Return Helper's Extension
	* @return Codzen_Pajucara_Helper_Data
	*/
	public function getHelper()
	{
		return Mage::helper('codzen_pajucara');
	}

	/**
	* Collect rates for this shipping method based on information in $request 
	* 
	* @param Mage_Shipping_Model_Rate_Request $data 
	* @return Mage_Shipping_Model_Rate_Result 
	*/
	public function collectRates(Mage_Shipping_Model_Rate_Request $request)
	{
		if ($this->_inicialCheck($request) === false) {
			return false;
		}

		// Verifica cep
		if (!preg_match('/^([0-9]{8})$/', $this->_toZip)) {
			$this->_msgErro = $this->getConfigData('pajucara_erro_2');
			$this->_throwError();
			return $this->_result;
		}

		$resposta = $this->getResponseWs($this->_toZip, $request);

		if($this->checkResposta($resposta)) {

			if($this->getConfigFlag('debug')) {
				$this->getHelper()->writeLog('VALOR ESTIMADO: '.$this->_totalPrice);
			}

			$method = Mage::getModel('shipping/rate_result_method');
			$method->setCarrier($this->_code);
			$method->setCarrierTitle($this->getConfigData('title'));
			$method->setMethod($this->_code);
			$method->setMethodTitle($this->getConfigData('title'));
			$method->setPrice((float)$this->_totalPrice);
			$method->setCost((float)$this->_totalPrice);
			$this->_result->append($method);

			$this->_updateFreeMethodQuote($request);
		}
		else {
			$this->_throwError();
		}
		return $this->_result;
	}


	/**
	 * Return if Response of WebService is valid to show a user
	 * 
	 * @param string
	 * @return mixed
	 */
	public function checkResposta($answer)
	{
		$xml_obj = simplexml_load_string($answer);
		if ($xml_obj) {
			if(is_numeric(strpos($xml_obj->OBSERVACAO,'ERRO'))) {
				switch (str_replace('ERRO:', '', $xml_obj->OBSERVACAO)) {
					case '-1':
						$erro = $this->getConfigData('pajucara_erro_1');
						break;
					case '-2':
						$erro = $this->getConfigData('pajucara_erro_2');
						break;
					case '-3':
						$erro = $this->getConfigData('pajucara_erro_3');
						break;
					case '-4':
						$erro = $this->getConfigData('pajucara_erro_4');
						break;
					case '-5':
						$erro = $this->getConfigData('pajucara_erro_5');
						break;
					case '-6':
						$erro = $this->getConfigData('pajucara_erro_6');
						break;
					default:
						$erro = $this->getConfigData('pajucara_erro_100');
						break;
				}
				$this->_msgErro = $erro;
				return false;
			}
			else {
				$this->_totalPrice = (float)trim($xml_obj->FRETE);
				return true;
			}
		}
	}

	public function getResponseWs($cep, $request)
	{
		$url = $this->getUrlWebservice();

		if(!$url) {
			$this->_msgErro = $this->getConfigData('url_ws_nao_encontrada');
			$retorno = NULL;
		}

		$info 	= $this->getInformations($request);
		$chave 	= $this->getConfigData('chave_autenticacao');
		$cnpj 	= $this->getConfigData('cnpj');

		try {
			// TODO: BUSCAR VALOR DAS VARS, DADOS DE TESTE DO WS
			$parameters = array(
				'service' => array(	'id' => '10993',
									'pars1' => $this->_fromZip,
									'pars2' => $this->_toZip,
									'pars3' => $info['weight'],
									'pars4' => '1000',
									'pars5' => '296',
									'pars6' => '51'),
				'identification' => array(	'userid' => $cnpj,
											'authorization' => $chave));

			$xml_data = $this->getHelper()->getXML($parameters);

			if($this->getConfigFlag('debug')) {
				$this->getHelper()->writeLog($xml_data);
			}

			$ws = new SoapClient($url,
				array(
					'trace'                 => 1,
					'exceptions'            => 1,
					'connection_timeout'    => $this->getConfigData('timeout') ? $this->getConfigData('timeout') : 30,
					'style'                 => SOAP_DOCUMENT,
					'use'                   => SOAP_LITERAL,
					'soap_version'          => SOAP_1_1,
					'encoding'              => 'UTF-8'
					)
				);

			$retorno = $ws->Process($xml_data);

			if($this->getConfigFlag('debug')) {
				$this->getHelper()->writeLog($retorno);
			}
	    }
	    catch( SoapFault $fault ){
	        $retorno = NULL;
	        Mage::logException($fault);
	    }

	    return $retorno;
	}


	/**
	 * Return Informations of Products: Weight (Kg or Gr)
	 *
	 * @return float
	 */
	protected function getInformations($request)
	{
		$items = $this->_getItems($request);
		$totalWeight = 0.0;

		foreach($items as $item) {
			$_product = $item->getProduct();

			$totalWeight += ((float)$item->getQty()) * (float)$_product->getWeight();
		}

		if($this->getConfigData('weight_format') == 'gr') {
			$totalWeight = (float)$totalWeight * 1000.00; 
		}

		$retorno = array(
			'weight' => $totalWeight
		);

		return $retorno;
	}


	/**
	* Get Subtotal of Quote Cart
	* 
	* @return string
	*/
	protected function geraValor()
	{
		$totals = Mage::getSingleton('checkout/cart')->getQuote()->getTotals();
		$subtotal = $totals["subtotal"]->getValue();
		return $subtotal;
	}


	/**
	 * Retrieve all visible items from request
	 *
	 * @param Mage_Shipping_Model_Rate_Request $request Mage request
	 * @return array
	 */
	protected function _getItems($request)
	{
		$allItems = $request->getAllItems();
		$items = array();
		
		foreach ( $allItems as $item ) {
			if ( !$item->getParentItemId() ) {
				$items[] = $item;
			}
		}
		
		$items = $this->_loadBundleChildren($items);
		return $items;
	}
	
	/**
	 * Filter visible and bundle children products.
	 *
	 * @param array $items Product Items
	 * @return array
	 */
	protected function _loadBundleChildren($items)
	{
		$visibleAndBundleChildren = array();
		/* @var $item Mage_Sales_Model_Quote_Item */
		foreach ($items as $item) {
			$product = $item->getProduct();
			$isBundle = ($product->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE);
			if ($isBundle) {
				/* @var $child Mage_Sales_Model_Quote_Item */
				foreach ($item->getChildren() as $child) {
					$visibleAndBundleChildren[] = $child;
				}
			} else {
				$visibleAndBundleChildren[] = $item;
			}
		}
		return $visibleAndBundleChildren;
	}

	/**
	 * Initial Check
	 * @return boolean
	 */
	protected function _inicialCheck($request)
	{
		if (!$this->getConfigFlag('active')) {
			Mage::log('codzen_pajucara: Disabled');
			return false;
		}

		$origCountry = Mage::getStoreConfig('shipping/origin/country_id', $this->getStore());
		$destCountry = $request->getDestCountryId();
		if ($origCountry != 'BR' || $destCountry != 'BR') {
			$this->getHelper()->writeLog($this->__('Out of Area'));
			return false;
		}

		$this->_fromZip = Mage::getStoreConfig('shipping/origin/postcode', $this->getStore());
		$this->_toZip   = $request->getDestPostcode();

		// Retira caracteres invalidos dos ceps
		$this->_fromZip = str_replace(array('-', '.'), '', trim($this->_fromZip));
		$this->_toZip   = str_replace(array('-', '.'), '', trim($this->_toZip));

		if (!preg_match('/^([0-9]{8})$/', $this->_fromZip)) {
			Mage::log('codzen_pajucara: From ZIP Code Error');
			return false;
		}

		$this->_result       = Mage::getModel('shipping/rate_result');
	}
	/**
	 * Return URL of Pajucara Web Service
	 *
	 * @return string
	 */
	public function getUrlWebservice()
	{
		return $this->getConfigData('url_ws_pajucara');
	}

	/**
	 * Returns the allowed carrier methods
	 *
	 * @return array
	 */
	public function getAllowedMethods()
	{
		return array($this->_code => $this->getConfigData('name'));
	}

	/**
	 * Define ZIP Code as required
	 *
	 * @param string $countryId Country ID
	 *
	 * @return bool
	 */
	public function isZipCodeRequired($countryId = null)
	{
		return true;
	}

	/**
	 * Throw error to frontend
	 *
	 * @return Mage_Shipping_Model_Rate_Result_Error
	 */
	protected function _throwError()
	{
		$this->_result = null;
		$this->_result = Mage::getModel('shipping/rate_result');

		$error = Mage::getModel('shipping/rate_result_error');
		$error->setCarrier($this->_code);
		$error->setCarrierTitle($this->getConfigData('title'));
		$error->setErrorMessage($this->_msgErro);
		$this->_result->append($error);
	}
}
?>