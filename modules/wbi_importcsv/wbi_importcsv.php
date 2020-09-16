<?php
/**
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Wbi_importcsv extends Module
{
    /**
    * Required headers in the csv file for product atributes.
    *
    * @var array
    */
    private $expectedAttributeHeaders;

    /**
    * Array asociative of position related with the headers.
    *
    * @var array
    */
    private $csvAttributeHeaderPosition;

    /**
    * Generate expected attribute header and retrieves shops data.
    */
    public function __construct()
    {
        $this->name = 'wbi_importcsv';
        $this->tab = 'export';
        $this->version = '1.0.0';
        $this->author = 'Web Impacto';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Web Impacto CSV Importer');
        $this->description = $this->l('Imports csv files into Prestashop');

        $this->confirmUninstall = $this->l('');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);

        $this->csvAttributeHeaders = [];

        $this->expectedAttributeHeaders = [
            'Nombre',
            'Referencia',
            'EAN13',
            'Precio de coste',
            'Precio de venta',
            'IVA',
            'Cantidad',
            'Categorias',
            'Marca'
        ];
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $output = null;

        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitWbi_importcsvModule')) == true) {
            $output .= $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitWbi_importcsvModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Product Importer'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                       'type' => 'file',
                       'label' => $this->l('Upload product file (.csv)'),
                       'name' => 'WBI_IMPORTCSV_PRODUCT_FILE',
                       'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $response = $this->uploadFile();
        $output = null;

        if ( isset($response['success']) ) {

            if ( $response['success'] ) {
                $output .= $this->displayConfirmation($response['message']);
            } else {
                $output .= $this->displayError($response['message']);
            }
        }

        return $output;
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    /**
    * Upload csv file and import all products/categories/brands into the store.
    * @return array status of request
    */
    public function uploadFile () 
    {
        try {

            // Validate file existance
            $filename = $this->getFileName();
            if ( empty($filename) ) {
                return [ 'success' => false, 'message' => 'The file couldn\'t be found'];
            }

            // Validate file extension
            $extension = $this->getFileExtension();
            if ( !$this->isExtensionCorrect($extension) ) {
                return [ 'success' => false, 'message' => 'The file must be in .cvs format, not "' . $extension . '"'];
            }

            // Open file
            $fp = fopen($filename, 'r');
            if( !$fp ) {
                return [ 'success' => false, 'message' => 'The file couldn\'t be opened'];
            }

            // Get file size
            $size = $this->getFileLines($fp);

            // Get file header
            $csvAttributeHeaders = fgetcsv($fp, 0, ',');
        
            // Validate file headers
            if (!$this->isHeaderCorrect($csvAttributeHeaders)) {
                return [ 'success' => false, 'message' => 'The file doesn\'t have a required header'];
            }

            // Retrieve the headers positions
            foreach ($this->expectedAttributeHeaders as $header) {
                $index = array_search($header, $csvAttributeHeaders);
                $this->csvAttributeHeaderPosition[$header] = $index;
            }

            $products = [];

            // Read each csv line
            while($row = fgetcsv($fp, 0, ',')) {
                // Read each column from the row and
                // assign it in the product array.
                $products[] = $this->getProductRow($row);
            }

            // dump($products);

            $created = 0;
            // Create products in the store
            foreach ($products as $index => $product) {
                $instance = $this->createProduct($product);
                $created++;
            }

        } catch (Exception $e) {
            return [ 'success' => false, 'message' => $e->getMessage() ];
        } finally {
            fclose($fp);
        }

        return [ 
            'success' => true, 
            'message' => "CSV file imported successfully.<br>
                        $created records created"
        ];
    }

     /**
     * Retrieves name from uploaded file.
     * @return string filename
     */
    protected function getFileName()
    {
        return isset($_FILES['WBI_IMPORTCSV_PRODUCT_FILE']['tmp_name'])? $_FILES['WBI_IMPORTCSV_PRODUCT_FILE']['tmp_name'] : '';
    }

    /**
     * Retrieves extension from uploaded file.
     * @return string extension
     */
    protected function getFileExtension()
    {
        return isset($_FILES['WBI_IMPORTCSV_PRODUCT_FILE']['type'])? $_FILES['WBI_IMPORTCSV_PRODUCT_FILE']['type'] : '';
    }

    /**
     * Validates if the file has the correct extension.
     * @param  string  $extension extension of the file
     * @return boolean if the extension is valid, valid extensions are csv and excel
     */
    protected function isExtensionCorrect($extension) 
    {
        return ($extension == 'application/vnd.ms-excel' || $extension == 'text/csv');
    }

    /**
     * Count the number of rows in the file.
     * @param  pointer $fp pointer of the file
     * @return integer number of rows counted
     */
    protected function getFileLines($fp)
    {
        $lines = 0;
        while( !feof($fp) ) {
            if( $line = fgets($fp) ) {
                $lines++;
            }
        }

        rewind($fp);

        return $lines;
    }

    /**
     * Validates if the file has the correct header
     * @param  [type]  $headers [description]
     * @return boolean          [description]
     */
    public function isHeaderCorrect ($headers) 
    {
        foreach ($this->expectedAttributeHeaders as $header) {
            if (!in_array($header, $headers))
                return false;
        }

        return true;
    }

    /**
     * Get the row of the product.
     * @param  [type] $row [description]
     * @return [type]      [description]
     */
    protected function getProductRow($row)
    {
        $product = [];

        foreach ($this->csvAttributeHeaderPosition as $header => $position) {

            switch ($header) {
                case 'Nombre':
                    $product['name'] = $row[$position];
                    break;
                case 'Referencia':
                    $product['reference'] = $row[$position];
                    break;
                case 'EAN13':
                    $product['ean13'] = $row[$position];
                    break;
                case 'Precio de coste':
                    $product['price-cost'] = $row[$position];
                    break;
                case 'Precio de venta':
                    $product['price-sell'] = $row[$position];
                    break;
                case 'IVA':
                    $product['iva'] = $row[$position];
                    break;
                case 'Cantidad':
                    $product['quantity'] = $row[$position];
                    break;
                case 'Categorias':
                    $product['categories'] = $row[$position];
                    break;
                case 'Marca':
                    $product['brand'] = $row[$position];
                    break;
            }

        }

        return $product;
    }

    /**
     * Creates a product in the store.
     * @param  array $array data required for creating the product
     * @return Product        prestashop Product instance
     */
    protected function createProduct($array)
    {
        $root_category = Category::getRootCategory();
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $product = new Product();

        // Name
        $product->description = [$lang => $array['name']];
        $product->short_description = [$lang => $array['name']];
        $product->name = [$lang => $array['name']];

        // Reference
        $product->reference = $array['reference'];

        // Ean 13
        $product->ean13 = $array['ean13'];

        $product->link_rewrite = [$lang => 'product'];

        // Price cost
        $product->wholesale_price = (float) $array['price-cost'];
        $product->wholesale_price = $this->calculatePrice($product->wholesale_price);

        // Price sell
        $product->price = (float) $array['price-sell'];
        $product->price = $this->calculatePrice($product->price);

        // IVA
        $product->id_tax_rules_group = $this->getIVA($array['iva']);

        // Quantity
        $product->quantity = $array['quantity'];
        $product->miminal_quantity = 0;

        // Root category
        $product->id_category = $root_category->id;
        $product->id_category_default = $root_category->id;

        $product->redirect_type = '404';
        $product->show_price = 1;

        // Brand
        if( !($brand_id = $this->getBrand($array['brand'])) ) {
            $brand_id = $this->createBrand( $array['brand'] );
        }

        $product->id_manufacturer = $brand_id;

        // Add it into the store, also add it to root category
        $product->add();
        $product->addToCategories([$root_category->id]);

        // Stock
        StockAvailable::setQuantity((int)$product->id, 0, $product->quantity, Context::getContext()->shop->id);

        // Search indexing
        Search::indexation(false, $product->id);

        // Categories
        $categories = explode(';', $array['categories']);
        $this->addCategories($product, $categories);

        return $product;
    }

    /**
     * Calculates the pricement based on formula.
     * Used in update and create.
     * @param  [type] $price [description]
     * @return [type]        [description]
     */
    protected function calculatePrice($price)
    {
        return $price;
    }

    /**
     * Searchs a product in Prestashop by it's reference.
     * @param  [type] $reference [description]
     * @return [type]            [description]
     */
    protected function findProduct ($reference) 
    {
        $result = Db::getInstance()->getValue("
            SELECT id_product as id 
            FROM ". _DB_PREFIX_ ."product 
            WHERE reference = '". $reference . "';
        ");

        return $result ? $result : ['id' => null];
    }

    /**
     * Retrieves Prestashop's Brand instance
     * @param  integer $brand_name name of the brand
     * @return Brand|null           Brand instance or null if not found
     */
    protected function getBrand($brand_name)
    {
        $manufacturer = Manufacturer::getIdByName($brand_name);

        if( is_nan($manufacturer) && $manufacturer == false ) {
            $manufacturer = null;
        } else {
            $manufacturer = new Manufacturer($manufacturer);
        }

        return $manufacturer->id;
    }

    /**
     * [createBrand description]
     * @param  [type] $brand_code [description]
     * @param  [type] $brand_name [description]
     * @return [type]             [description]
     */
    protected function createBrand($brand_name)
    {
        // In prestashop, we considerited the brand as a manufacturer.
        // Create the manufacturer
        $manufacturer = new Manufacturer();
        $manufacturer->name = $brand_name;
        $manufacturer->active = 1;
        $manufacturer->save();

        return $manufacturer->id;
    }

    /**
     * Search categories and creates them if not found,
     * then, assigns them into producto.
     * @param [type] $product    [description]
     * @param [type] $categories [description]
     */
    public function addCategories($product, $categories)
    {
        $lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $root_category = Category::getRootCategory();

        foreach ($categories as $category) {
            
            // Search category
            $found_categories = Category::searchByNameAndParentCategoryId($lang, $category, $root_category->id);

            $new_category = null;
            if( !$found_categories ) {  // Not found, create it
                $link = Tools::link_rewrite( $category);

                $new_category = new Category();
                $new_category->name = [$lang => $category];
                $new_category->id_parent = $root_category->id;
                $new_category->is_root_category = false;
                $new_category->active = 1;
                $new_category->link_rewrite = [$lang => $link];
                $new_category->add();
                $new_category->save();
            } else {                    // Found, retrieve and instance it
                $new_category = new Category($found_categories['id_category']);
            }

            if( $new_category ) { // If there's a category, assign it to product
                $product->addToCategories([$new_category->id]);
            }
        }
    }

    public function getIVA($iva)
    {
        $tax_name = "WEB IMPACTO IVA $iva%";

        // Search Tax Rule Group by name
        $id_tax_rules_group = TaxRulesGroup::getIdByName($tax_name);

        // Found rule
        if( $id_tax_rules_group != false ) {
            return $id_tax_rules_group;
        } 

        // There's no Tax Rule Group, create a new one
        $lang = Context::getContext()->language->id;

        // Create the Tax
        $tax = new Tax();
        $tax->name = [$lang => $tax_name];
        $tax->rate = $iva;
        $tax->active = true;
        $tax->add();

        // Then the group rule
        $tax_rules_group = new TaxRulesGroup();
        $tax_rules_group->active = true;
        $tax_rules_group->name = $tax_name;
        $tax_rules_group->add();

        // Then the individual tax rules for each country / states
        $countries = Country::getCountries($lang);
        
        $selected_countries = array();
        foreach ($countries as $country) {
            $selected_countries[] = (int) $country['id_country'];
        }

        $selected_states = array(0);
        foreach ($selected_countries as $id_country) {

            foreach ($selected_states as $id_state) {

                $id_rule = null;
                $zip_code = 0;

                $tr = new TaxRule();

                $tr->id_tax = $tax->id;
                $tr->id_tax_rules_group = (int)$tax_rules_group->id;
                $tr->id_country = (int)$id_country;
                $tr->id_state = (int)$id_state;
                list($tr->zipcode_from, $tr->zipcode_to) = $tr->breakDownZipCode($zip_code);
                $tr->behavior = (int) 0;
                $tr->description = '';
                $tr->id = (int)$tax_rules_group->getIdTaxRuleGroupFromHistorizedId((int)$tr->id);
                $tr->id_tax_rules_group = (int)$tax_rules_group->id;
                $tr->save();
            }
        }

        return $tax_rules_group->id_tax_rules_group;
    }
}
