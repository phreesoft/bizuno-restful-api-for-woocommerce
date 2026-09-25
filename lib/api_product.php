<?php
/**
 * Bizuno API WordPress Plugin - product class
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade Bizuno to newer
 * versions in the future. If you wish to customize Bizuno for your
 * needs please contact PhreeSoft for more information.
 *
 * @name       Bizuno ERP
 * @author     Dave Premo, Bizuno Project <support@bizuno.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-09-19 (volume pricing table shows the Bizuno sell unit label when the tiers carry one)
 * @filesource /lib/product.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class api_product extends api_common
{
    public $productID = 0;
    private $bizProduct;
    private $canWrite;
    private $fileBirdActive;
    private $locale = [
        'msg_sku_missing'     => "The following SKUs are on the cart but are not flagged to be there by ",
        'msg_sku_sync_success'=> "All products are in Sync.",
    ];

    function __construct()
    {
        global $wp_filesystem;
        parent::__construct();
        $this->fileBirdActive = is_plugin_active ( 'filebird/filebird.php' ) || is_plugin_active ( 'filebird-pro/filebird.php' ) ? true : false;
        if ( ! function_exists( 'WP_Filesystem' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $this->canWrite = ! WP_Filesystem() ? \bizuno_api_msg_add("Cannot create image path: $image_dir") : true;
    }

    /********************** Cron Events ************************/
    public function cron_image()
    {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        // This takes a LONG LONG LONG time, typically makes the script time out so it was separated from the main upload script and moved here to a cron

        $imageQueue = \get_option('bizuno_image_queue');
        if (empty($imageQueue)) { return; } // queue is empty, nothing to do
        foreach ($imageQueue as $image_id => $filename) {
            $attach_data = \wp_generate_attachment_metadata( $image_id, $filename );

            \wp_update_attachment_metadata( $image_id, $attach_data ); // TAKES REALLY LONG, UP TO A MINUTE, MOVE TO CRON

            unset($imageQueue[$image_id]);
            \update_option( 'bizuno_image_queue', $imageQueue ); // save as we go if the script times out the queue will still be reduced for the next iteration
        }
    }

    /********************** REST Endpoints ************************/
    public function product_update($request)
    {
        $data  = $this->rest_open($request);
        $postID= $this->productImport($data['data']);
        $output= ['result'=>!empty($postID)?'Success':'Fail', 'ID'=>$postID];
        return $this->rest_close($output);
    }
    public function product_refresh($request)
    {
        $data   = $this->rest_open($request);
        $success= $this->productRefresh($data['data']);
        $output = ['result'=>!empty($success['result'])?'Success':'Fail', 'acted'=>!empty($success['acted'])?(int)$success['acted']:0, 'note'=>!empty($success['note'])?$success['note']:''];
        return $this->rest_close($output);
    }
    public function product_sync($request)
    {
        $data  = $this->rest_open($request);
        $result= $this->productSync($data['data']);
        $output= ['result'=>$result?'Success':'Fail'];
        return $this->rest_close($output);
    }

    /************** Product Hooks & Shortcodes ******************/
    public function bizuno_api_price_discounts_sc()
    {
    global $product;

    // Safety check
    if (!is_a($product, 'WC_Product') || !$product->is_visible()) { return; }

    $tiers = $product->get_meta('_bizuno_price_tiers', true);

    // Only show if tiers exist and are a valid array
    if (empty($tiers) || !is_array($tiers)) { return; }

    // Ensure tiers are sorted by quantity ascending (just in case)
    usort($tiers, function($a, $b) { return (int)$a['qty'] <=> (int)$b['qty']; });
    $pack_size = (int)$tiers[0]['qty'];
    $has_units = false; // Bizuno 7.4.7+ sends the sell unit name (e.g. "Blister Card (5 pieces)") with each tier
    foreach ($tiers as $tier) { if (!empty($tier['label'])) { $has_units = true; break; } }

    // Start output
    if ($pack_size > 1) {
        echo '<p style="font-size: 14px; color: #666; margin: 10px 0;">
                <strong>Note:</strong>'. esc_html( sprintf ( 'Sold in packages of %d. Quantity must be in multiples of %d', $pack_size, $pack_size ) ) . '</p>';
    }
    ?>
    <div class="bizuno-volume-pricing-table" style="margin: 30px 0; padding: 20px; background: #f9f9f9; border: 1px solid #e1e1e1; border-radius: 8px; font-family: Arial, sans-serif;">
        <h4 style="margin: 0 0 15px 0; color: #333;">Volume Pricing</h4>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <thead>
                <tr style="background: #eee;">
                    <?php if ($has_units): ?><th style="text-align: left; padding: 10px; border-bottom: 2px solid #ddd;">Sell Unit</th><?php endif; ?>
                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid #ddd;">Quantity</th>
                    <th style="text-align: right; padding: 10px; border-bottom: 2px solid #ddd;">Price Each</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tiers as $tier): ?>
                    <?php 
                    $qty   = (int)$tier['qty'];
                    $price = (float)$tier['price'];
                    ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <?php if ($has_units): ?><td style="padding: 10px;"><?php echo esc_html(!empty($tier['label']) ? $tier['label'] : ''); ?></td><?php endif; ?>
                        <td style="padding: 10px;"><?php echo esc_html($qty . '+'); ?></td>
                        <td style="padding: 10px; text-align: right; font-weight: bold; color: #d63384;">
                            <?php echo wp_kses_post( wc_price( $price ) ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin: 15px 0 0; font-size: 12px; color: #666;">
            Discount applied automatically in cart based on quantity.
        </p>
    </div><p> </p>
    <?php
    }

    /************** API Product Processing ******************/
    /**
     * Starts the import of product to WooCommerce
     * @param type $post
     * @return type
     */
    public function productImport($post=[])
    {

//      set_time_limit(60); // I don't think this is needed anymore, images are processed via cron
        if (!is_array($post) || empty($post['SKU'])) { return \bizuno_api_msg_add("Bad SKU passed. Needs to be the inventory field id tag name (SKU)."); }

        $this->bizProduct = $this->getProduct($post);
        
        $slug = !empty($post['WooCommerceSlug']) ? $post['WooCommerceSlug'] : $post['Description'];
        if (isset($post['WeightUOM'])) { // convert weight (need to convert kg,lb,oz,g)
            $weightUOM= !empty($post['WeightUOM']) ? strtolower($post['WeightUOM']) : 'lb';
            $wooWt    = \get_option('woocommerce_weight_unit');
            $wp_weight= !empty($wooWt) ? strtolower($wooWt) : 'lb';
            $weight   = isset($post['Weight']) ? $this->convertWeight($post['Weight'], $weightUOM, $wp_weight) : '';
        }
        if (isset($post['DimensionUOM'])) { //convert dim (need to convert m,cm,mm,in,yd)
            $dim = strtolower($post['DimensionUOM']);
            $wordpress_dim = strtolower(\get_option('woocommerce_dimension_unit'));
            $length = isset($post['ProductLength'])? $this->convertLength($post['ProductLength'],$dim, $wordpress_dim) : '';
            $width  = isset($post['ProductWidth']) ? $this->convertLength($post['ProductWidth'], $dim, $wordpress_dim) : '';
            $height = isset($post['ProductHeight'])? $this->convertLength($post['ProductHeight'],$dim, $wordpress_dim) : '';
        }
        // Let's go
        $product_id = $this->bizProduct->get_id();

        $this->bizProduct->set_date_modified(\wp_date('Y-m-d H:i:s'));
        $this->bizProduct->set_description(!empty($post['DescriptionLong']) ? $post['DescriptionLong'] : $post['DescriptionSales']);
        $this->bizProduct->set_length($length);
        $this->bizProduct->set_width($width);
        $this->bizProduct->set_height($height);
        $this->bizProduct->set_weight($weight);
        $this->bizProduct->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
        $this->bizProduct->set_backorders($this->options['inv_backorders']);
        $this->bizProduct->set_menu_order(!empty($post['MenuOrder']) ? (int)$post['MenuOrder'] : 99);
        $this->bizProduct->set_name($post['Description']);

        $this->bizProduct->set_price(floatval($post['Price']));
        $this->bizProduct->set_regular_price(floatval($post['Price']));
        $this->bizProduct->set_sale_price('');
//      $this->bizProduct->set_regular_price(!empty($post['RegularPrice']) ? $post['RegularPrice'] : '');
//      $this->bizProduct->set_sale_price(!empty($post['SalePrice']) ? $post['SalePrice'] : '');
        $this->priceTiers($this->bizProduct, !empty($post['PriceTiers']) ? $post['PriceTiers'] : []);
        $this->bizProduct->set_short_description(!empty($post['DescriptionSales']) ? $post['DescriptionSales'] : $post['Description']);
        $this->bizProduct->set_slug($this->getPermaLink($slug));
//      $this->bizProduct->set_status('published');
        $this->bizProduct->set_stock_quantity($post['QtyStock'] > 0 ? $post['QtyStock'] : 0);
        $this->bizProduct->set_stock_status($post['QtyStock'] > 0 ? 'instock' : 'outofstock');
        $this->bizProduct->set_tax_status('taxable');

        switch ($post['sendMode']) {
            default: // default needs to be here so the individula upload sends everyhthing.
            case 1: $replaceImage = true;// Full Upload (Slowest - replace/regenerate all images)
            case 2: // Full Product Details (Skip images if present)
                $this->productImage($post, $product_id, !empty($replaceImage) ? true : false); // Set images
            case 3: // Product Core Info (No Categories/Images)
                $this->productAttributes($post, $product_id); // Update attributes
                $this->productRelated($post); // Set related products
                if (!empty($post['invOptions'])) { $this->productVariations($post['invOptions'], $product_id); } // check for master stock type
                $this->productMetadata($post);
                $this->productTags($post, $product_id);
                $this->productCategory($post, $product_id); //update category
                break;
        }

        $this->bizProduct->save();
        return $product_id;
    }

    private function getProduct($post)
    {
        global $wpdb;
        $this->productID = \wc_get_product_id_by_sku($post['SKU']);

        if (empty($this->productID)) { // The new way returns zero for products uploaded in early versions of the API, try to old way, just in case
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $this->productID = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1", $post['SKU'] ) );

        }
        $productType = !empty($post['Type']) ? strtolower($post['Type']) : 'si'; // allows change of product type on the fly
        if ( empty($this->productID) ) { // new product

            if ('ms'===$productType) {

                $product =  new \WC_Product_Variable();
            } else {

                $product =  new \WC_Product_Simple();
            }
            $product->set_sku($post['SKU']);
//          $product->set_date_created(!empty($post['DateCreated']) ? $post['DateCreated'] : \wp_date('Y-m-d H:i:s'));
            $product->save(); // get an ID

        } else { // update existing product
            $product   = \wc_get_product( $this->productID );
            $changeType= false;
            if ($product->is_type( 'simple' )  && ('ms'===$productType ) ) { $changeType = 'variable'; }
            if ($product->is_type( 'variable' )&& ('ms'<> $productType ) ) { $changeType = 'simple'; }
            if (!empty($changeType)) { $this->productChangeType($product, $changeType); }
        }
        return $product;
    }

    private function productChangeType(&$product, $changeType='simple')
    {

        if ($changeType === 'simple') { // need to remove the variations
            $variations = $product->get_children();  // Gets variation IDs
            if ($variations) {
                foreach ($variations as $variation_id) {
                    $variation = \wc_get_product($variation_id);
                    if ($variation) { $variation->delete(true); } // true = force delete (permanent)
                }
                \wc_delete_product_transients($this->productID);
            }
        }
        $classname= \WC_Product_Factory::get_product_classname( $this->productID, $changeType );
        $product= new $classname( $this->productID );
        $product->save();
    }

    private function productRelated($post)
    {
        global $wpdb;

        // This needs to be updated to the new method, probably part of WC_Product_Simple
        //
        //
        // fetch related id
        if (!empty($post['invAccessory']) && is_array($post['invAccessory'])) {
            $post['related'] = [];
            foreach ($post['invAccessory'] as $related) {
                $product_id = wc_get_product_id_by_sku( $related );
                if ($product_id !== false) { $post['related'][] = $product_id; }
            }

        }
    }

    private function productMetadata($post)
    {
        if (!empty($post['SearchCode']))      { $this->bizProduct->update_meta_data('biz_search_code',      $post['SearchCode']); }

        if ( !is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) { return; }
        if (!empty($post['MetaDescription'])) { $this->bizProduct->update_meta_data('_yoast_wpseo_metadesc',$post['MetaDescription']); }
    }

    /**
     * Set the tags
     * @param type $post
     * @param type $product_id
     * @return boolean
     */
    private function productTags($post, $product_id)
    {

        if (empty($post['WooCommerceTags'])) { return; }
        $IDs = [];
        $current = \get_the_terms($product_id, 'product_tag');

        foreach ( (array)$current as $term) {
            if (!empty($term->name)) { $IDs[] = $term->name; }
        }
        $sep = strpos($post['WooCommerceTags'], '|') !== false ? '|' : ';'; // new separator is the |
        $tags= explode($sep, $post['WooCommerceTags']);
        foreach ($tags as $tag) {
            if (!empty(trim($tag))) { $IDs[] = trim($tag); } // sanitize_title makes the slug (lower no spaces) and also is used as the label which we don't want
        }

        $results = \wp_set_object_terms($product_id, $IDs, 'product_tag');

    }

    /**
     *
     * @param type $post
     * @param type $product_id
     * @return boolean
     */
    private function productCategory($post, $product_id)
    {

        if (empty($post['WooCommerceCategory'])) {
            return \bizuno_api_msg_add("Error - the category was not passed for product: {$post['SKU']}, it must be set manually in WooCommerce.", 'caution');
        }
        $this->endCatOnly = false;

        // Multiple category breadcrumbs may be passed, use semi-colon as the separator
        $categories = explode(";", $post['WooCommerceCategory']);
        foreach ($categories as $category) {
            $parent = 0;
            $descName = $niceName = '';
            $sep = strpos($category, '|')!== false ? '|' : ':'; // new separator is the |
            $breadcrumbs = explode($sep, $category);
            foreach ($breadcrumbs as $breadcrumb) { // starts at the top level and goes down.
                $term = trim($breadcrumb);
                if (empty($term)) { continue; }
                $descName = trim($term);
                $niceName.= '-'.trim(strtolower(str_replace([':',' '], '-', $term)), " -"); // grow the nice name with the category
                $termIDs  = term_exists( $descName, 'product_cat', !empty($parent)?$parent:null );

                if (empty($termIDs)) {
                    $termData= [ 'slug'=>$niceName, 'parent'=>$parent ]; // 'description'=>$descName, // leave description blank so user can edit through WooCommerce

                    $termIDs = wp_insert_term( $descName, 'product_cat', $termData );
                }

                wp_set_post_terms( $product_id, [$termIDs['term_id']], 'product_cat', $this->endCatOnly?false:true );
                $parent = $termIDs['term_id'];
            }
            // Check just the lowest on the category tree if endCatOnly is true
            if ($this->endCatOnly) {
                wp_set_post_terms( $product_id, [$termIDs['term_id']], 'product_cat', true );
//              $this->bizProduct->set_category_ids();  // New way
            }
        }
        return true;
    }

    /**
     * Add product attributes, this method just hard codes the value and avoids terms and taxonomy which creates a new term for every possible value
     * @param type $post
     * @param type $product_id
     * @return type
     */
    private function productAttributes($post, $product_id)
    {
        global $wpdb;

        if (empty($post['Attributes'])) { return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
        $result = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->term_taxonomy WHERE taxonomy LIKE %s", $wpdb->esc_like( 'pa_' ) . '%' ), ARRAY_A );
        $pa_attr_ids= [];
        foreach ($result as $row) { $pa_attr_ids[] = $row['term_taxonomy_id']; }
        if (sizeof($pa_attr_ids)) { // clear out the current attributes
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id IN (" . implode(',', array_fill(0, count($pa_attr_ids), '%d')) . ")",
                array_merge( [ (int)$product_id ], array_map('intval', $pa_attr_ids) ) ) );
        }
        $productAttr= [];
        foreach ($post['Attributes'] as $idx => $row) {
            if (empty($row['title']) || empty($row['index'])) { continue; }
            $attrSlug= $this->getPermaLink($row['index']);
//          $attrSlug= $this->getPermaLink($post['AttributeCategory'].'_'.strtolower($row['index'])); // creates a lot of attributes and causes filtering issues
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $exists  = $wpdb->get_var( $wpdb->prepare( "SELECT attribute_name FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s", $attrSlug));
            if (!$exists) {
                $newAttr = [
                    'attribute_name'     => sanitize_title( $attrSlug ),
                    'attribute_label'    => sanitize_text_field( $row['title'] ),
                    'attribute_type'     => 'text',
                    'attribute_orderby'  => 'name_num',
                    'attribute_public'   => 0];
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
                $wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $newAttr, [ '%s', '%s', '%s', '%s', '%d'] );
//                dbWrite(SOME_DB_PREFIX.'woocommerce_attribute_taxonomies', $newAttr);
            }
            $productAttr["pa_$attrSlug"] = ['name'=>$row['title'],'value'=>$row['value'],'position'=>$idx,'is_visible'=>1,'is_variation'=>0,'is_taxonomy'=>0];
            // Update postmeta with attribute key => value pair for searching...
            update_post_meta( $product_id, "biz_".strtolower($row['index']), $row['value'] );
        }

        update_post_meta( $product_id, '_product_attributes', $productAttr );
    }

    /**
     *
     * @param type $value
     * @return type
     */
    private function getPermaLink($value)
    {
        $test1 = str_replace(':', '_', $value);
        $test2 = str_replace([' ', '/', '.'], '-', trim($test1));
        $test3 = preg_replace("/[^a-zA-Z0-9\-\_]/", "", $test2);
        while (strpos($test3, '--') !== false) { $test3 = str_replace('--', '-', $test3); }
        return strtolower($test3);
    }

    /**
     * Creates/Updates the variations for master stock type items
     * The variation data format, for each variation:
        $variation_data =  array( 'sku' => '','regular_price' => '22.00', 'sale_price' => '','stock_qty' => 10,
            'attributes' => array( 'size' => 'M', 'color' => 'Green', ) );
     * @param array $variations
     * @param integer $product_id
     * @return type
     */
    private function productVariations($variations, $product_id)
    {

        // Process the attributes
        $allAttrs = $this->bizProduct->get_attributes();
        $attrNames= [];
        foreach ($allAttrs as $tmp) { $attrNames[] = $tmp['name']; }
        $cnt      = 0;
        foreach ($variations['attributes'] as $attr) {

            $attribute = new \WC_Product_Attribute();
            $attribute->set_name( $attr['name'] );
            $attribute->set_options( $attr['options'] );
            $attribute->set_position( $cnt );
            $attribute->set_visible( true );
            $attribute->set_variation( true ); // here it is
            $allAttrs[$key] = $attribute;
            $key = array_search($attr['name'], $attrNames);
            if (false===$key) { $allAttrs[]     = $attribute; }
            else              {   $allAttrs[$key] = $attribute; }
            $cnt++;
        }
        $this->bizProduct->set_attributes( $allAttrs );
        // get the current variations keyed by sku for searching
        $existingIDs = $this->getCurrentVariations($product_id);
        // foreach variation in the request
        foreach ( $variations['variations'] as $value ) {

            $variation_id = 0;
            if (!empty($existingIDs)) { $variation_id = array_shift($existingIDs); }
            else { // make a new variation

                $product = \wc_get_product($product_id);
                $variation_post = [
                    'post_title'  => $product->get_name(),
                    'post_name'   => 'product-'.$product_id.'-variation',
                    'post_status' => 'publish',
                    'post_parent' => $product_id,
                    'post_type'   => 'product_variation',
                    'guid'        => $product->get_permalink()];
                $variation_id = \wp_insert_post( $variation_post );
            }

            $variation = new \WC_Product_Variation( $variation_id );

            $variation->set_sku( $value['sku'] );

            $variation->set_attributes( $value['attributes'] );
            $variation->set_weight(''); // weight (reseting)
            $variation->set_regular_price( $value['regular_price'] );
            if ( empty( $value['sale_price'] ) ) {
                $variation->set_price( $value['regular_price'] );
                $variation->set_sale_price( '' );
            } else {
                $variation->set_price( $value['sale_price'] );
                $variation->set_sale_price( $value['sale_price'] );
            }
            if ( ! empty($value['stock_qty']) ) {
                $variation->set_stock_quantity( $value['stock_qty'] );
                $variation->set_stock_status('');
            }
            $variation->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
            $variation->set_backorders($this->options['inv_backorders']);
            $variation->save(); // Save the data
        }

        $this->bizProduct->set_default_attributes( $variations['variations'][0]['attributes'] );
        // delete left over variants that are no longer used
        if (sizeof($existingIDs) > 0) { // We still have some more variations, delete them
            foreach ($existingIDs as $exID) {
                $variation_id = $exID->ID;

                $variation = new \WC_Product_Variation( $variation_id );
                $variation->delete();
            }
        }
    }

    /*
     * Get all variation ID's
     */
    private function getCurrentVariations($product_id)
    {
        $output = [];
        $args = ['post_type'=>'product_variation', 'post_status'=>array( 'private', 'publish' ),
            'numberposts'=>-1, 'orderby'=>'menu_order', 'order'=>'asc', 'post_parent'=>$product_id];
        $varIDs = get_posts( $args );
        foreach ($varIDs as $variation) {
            $variation_id = $variation->ID;
            $output[] = $variation_id;
        }

        return $output;
    }

    /**
     *
     * @param type $post
     * @param type $product_id
     * @return type
     */
    private function productImage($post, $product_id, $replace=false)
    {

        if (empty($post['ProductImageFilename'])) { return; }
        $media = [];
        // Note: wp-admin/includes/image.php is intentionally NOT loaded here.
        // This path only writes the file and queues the attachment id; the one
        // image.php function we use (wp_generate_attachment_metadata) runs later
        // in cron_image(), which loads image.php immediately before that call.
        $this->setImageProps($media, $post['ProductImageDirectory'], $post['ProductImageFilename'], $post['ProductImageData']);
        if (!empty($post['Images']) && is_array($post['Images'])) {

            foreach ($post['Images'] as $image) {
                $this->setImageProps($media, $image['Path'], $image['Filename'], $image['Data']);
            }
        } else { }
        if (empty($media)) {

            return;
        } // No images uploaded
        $this->setImageCleaner($product_id); // takes out the trash
        // ready to set images, since they are searched and id'ed based on the path, we only need the meta index to retain the position
        $props  = array_shift($media);
        $imgIdx = $this->setImage($props, $product_id, $replace);

        if (!empty($imgIdx)) {
//          update_post_meta( $product_id, '_thumbnail_id', $imgIdx ); // Old way
            $this->bizProduct->set_image_id($imgIdx);
        }
        // Set the image gallery (for the rest of the images)

        $xIDs   = [];
        foreach ($media as $props) {
            $imgIdx = $this->setImage($props, $product_id, $replace);
            if (!empty($imgIdx)) { $xIDs[] = $imgIdx; }
        }
        $this->bizProduct->set_gallery_image_ids($xIDs);
//      update_post_meta($product_id, '_product_image_gallery', implode(',', $xIDs));  // Old Way
    }

    private function setImageProps(&$media, $path='', $name='', $data='')
    {
        if (empty($data)) { return; }
        $tmp0 = 'products/'.(!empty($path) ? $path : ''); // from root upload folder
        $tmp1 = str_replace('/./', '/', $tmp0);
        $media[] = ['path' => rtrim($tmp1, '/').'/', 'name' => $name, 'data' => $data];
    }

    /**
     * Cleans out duplicates and other issues from earlier releases
     * @param type $activeIDs
     * @param type $product_id
     */
    private function setImageCleaner($product_id)
    {
        global $wpdb;

        // first check thumbnails for multiple records, should only be one
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
        $metaIDs = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'", $product_id ), ARRAY_A);
        
        if (sizeof($metaIDs) > 1) {
            for ($i=1; $i<sizeof($metaIDs); $i++) { // earlier bug where multiple thumbnails were generated

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
                $wpdb->delete( $wpdb->postmeta, [ 'meta_id' => (int) $metaIDs[$i]['meta_id'] ], [ '%d' ] );
                \wp_delete_post( $metaIDs[$i]['post_id'], true );
            }
        }
        // If the same image is used for multiple products, then multiple media posts were generated, clean these up and start over.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
        $dupImages = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_parent <> 0 AND post_parent = %d AND post_type = 'attachment' ORDER BY ID", $product_id ), ARRAY_A);
        foreach ($dupImages as $imageID) {

            \wp_delete_post( $imageID['ID'], true );
        }
    }

    /**
     * Uploads the image and puts it in the proper folder, creates to folder if it can.
     * @param array $props
     * @param integer $product_id
     * @param integer $replace
     * @return type
     */
    private function setImage($props, $product_id, $replace=false)
    {
        global $wpdb, $wp_filesystem;

        $upload_folder= wp_upload_dir();
        $image_dir    = $upload_folder['basedir']."/{$props['path']}";
        $filename     = $image_dir.$props['name']; // '/path/to/uploads/2013/03/filename.jpg';
        $guid         = $props['path'] . $props['name'];

        // BOF - Clean out duplicate image posts pointing to the same file
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
        $postIDs = $wpdb->get_results( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id", $guid ), ARRAY_A );

        for ($i=1; $i<sizeof($postIDs); $i++) { // earlier bug where multiple thumbnails were generated pointing to same file location

            \wp_delete_post( (int) $postIDs[$i]['post_id'], true );
        }
        if (sizeof($postIDs)>0) {
            $post_id = absint( $postIDs[0]['post_id'] ?? 0 );
            $postExists = get_post_status( $post_id ) !== false;

            if (empty($postExists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
                $wpdb->delete( $wpdb->postmeta, [ 'post_id' => (int) $postIDs[0]['post_id'] ], [ '%d' ] );
            }
            $imgID = !empty($postExists) ? $postIDs[0]['post_id'] :  0;
        } else {
            $imgID = 0;
        }
        // EOF - Clean out duplicate images

        if (!$props['data']) { return; } // no image was sent up to save, just return with no message
        // If skip overwrite and image is present, return with just the ID
        if (!$replace && !empty($imgID)) {

            return $imgID;
        }
        // NOTE: the str_replace is to necessary to fix a PHP 5 issue with spaces in the base64 encode... see php.net
        $contents    = base64_decode(str_replace(" ", "+", $props['data']));
        if (!$this->canWrite) { return; }
        // Check if directory already exists
        if ( ! $wp_filesystem->is_dir( $image_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $image_dir, 0755 ) ) { return \bizuno_api_msg_add( "Cannot create image folder: $image_dir" ); }
        }
        $full_path   = $image_dir.$props['name'];
        $dirname     = dirname($full_path);
        if ( ! $wp_filesystem->is_dir( $dirname ) ) {
            if ( ! $wp_filesystem->mkdir( $dirname, 0755 ) ) { return \bizuno_api_msg_add( "Cannot create image path: $dirname" ); }
        }
        $success = $wp_filesystem->put_contents( $full_path, $contents, 0644 );
        if ( ! $success ) { return \bizuno_api_msg_add( "Cannot write image file: $full_path" ); }

        $filetype = wp_check_filetype(basename( $filename ), null);
        $args = [
            'guid'          => $upload_folder['baseurl'] . "/$guid",
            'post_mime_type'=> $filetype['type'],
            'post_title'    => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
            'post_content'  => '',
            'post_type'     => 'attachment',
            'post_status'   => 'inherit'];
        if (!empty($imgID)) { $args['ID'] = $imgID; }

        $attach_id = \wp_insert_post( $args, true );

        if (!is_wp_error($attach_id)) {
            if ($attach_id==0) { $attach_id = $imgID; } // for some reason, WP returns 0 when the ID is set going into post and no error
            \update_post_meta( $attach_id, '_wp_attached_file', $guid ); // this needs to be there at a minimum or media details will not render image
            \update_post_meta( $attach_id, '_wp_attachment_metadata', ['file'=>$filename] ); // this needs to be there at a minimum or media details will not render image
            $fileParent = $this->getFBParent($props['path']);
            if (false !== $fileParent) { $this->setFBAttach($attach_id, $fileParent); }

            $imageQueue  = \get_option('bizuno_image_queue');
            if (empty($imageQueue)) {
                \add_option('bizuno_image_queue', []);
                $imageQueue = [];
            }
            $imageQueue[$attach_id] = $filename;
            \update_option( 'bizuno_image_queue', $imageQueue );

            return $attach_id;
        }

        return false;
    }

    /**
     *
     * @param type $entry
     * @return string
     */
    private function getImageType($entry)
    {
        $ext = strtolower(substr($entry, strrpos($entry, '.')+1));
        if (in_array($ext, ['png'])) { return 'image/png'; }
        if (in_array($ext, ['jpg','jpeg'])) { return 'image/jpeg'; }
        if (in_array($ext, ['gif'])) { return 'image/gif'; }
        return '';
    }

    private function getFBParent($path)
    {
        global $wpdb;

        if ( !$this->fileBirdActive ) { return; }
        $clnPath= rtrim(trim($path), '/');

        if (empty($clnPath)) { return false; }
        $dirs   = explode("/", $clnPath);

        $parent = 0;
        foreach ($dirs as $dir) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $result = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fbv WHERE name = %s AND parent = %d", $dir, $parent ) );
            if (is_null($result)) {

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
                $wpdb->insert( $wpdb->prefix . 'bsi_fbv', ['name'=>$dir, 'parent'=>$parent], ['%s', '%d'] );
//              $parent = dbWrite($wpdb->prefix.'fbv', ['name'=>$dir, 'parent'=>$parent]);  // no connected to 
            } else {

                $parent = $result->id;
            }
        }

        return $parent;
    }

    private function setFBAttach($attach_id, $fileParent)
    {
        global $wpdb;

        if ( !$this->fileBirdActive ) { return; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT folder_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id = %d AND attachment_id = %d LIMIT 1", absint( $fileParent ), absint( $attach_id ) ) );

        if (is_null($result)) {

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $result = $wpdb->insert( $wpdb->prefix . 'fbv_attachment_folder', ['folder_id'=>(int)$fileParent, 'attachment_id'=>(int)$attach_id], ['%d', '%d'] );
        }
    }

    /**
     * Inserts/updates the postmeta for a specified post_id
     * @param array $postData - for the post main record
     * @param array $postMeta - for the post meta table
     * @param integer $post_id - if known, database record, if empty then a new record will be created
     * @return integer - post id, relevant if a new record was created
     */
    protected function setPost($postData=[], $postMeta=[], $post_id=0)
    {
        $postData['ID'] = $post_id;
        $data = array_merge($postData, ['meta_input'=>$postMeta]);

        $postID = wp_insert_post($data, true);
        if (is_wp_error($postID)) {
            $errors = $postID->get_error_messages();

            return 0;
        }
        return $postID;
    }

    public function productRefresh($items = [], $verbose = true)
    {
        global $wpdb;
        if (empty($items)) { return ['result' => false, 'note' => 'No items provided']; }

        $cnt = 0;
        $missingSKUs = [];
        // Build list of SKUs to lookup
        $skus = array_filter(array_column($items, 'SKU'));
        if (empty($skus)) { return ['result' => true, 'note' => 'No valid SKUs found']; }
        // Batch lookup WooCommerce products by SKU (safe, checker-compliant)
        $skus = array_unique( array_filter( array_map( 'trim', $skus ) ) );
        if ( empty( $skus ) ) {
            $items = [];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fast SKU lookup on core table; caching not needed for one-off admin/sync use
            $rows = $wpdb->get_results( $wpdb->prepare( 
                "SELECT meta_value AS sku, post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value IN (" . implode( ',', array_fill( 0, count( $skus ), '%s' ) ) . ")",
                ...$skus ), OBJECT_K );
        }
        foreach ($items as $item) {

            $sku = trim($item['SKU'] ?? '');
            if ($sku === '') { continue; }

            if (!isset($rows[$sku])) { $missingSKUs[] = $sku; continue; }
            $product_id = $rows[$sku]->post_id;
            $product = wc_get_product($product_id); // Proper WC_Product object
            if (!$product) { $missingSKUs[] = $sku; continue; }

            $needs_save = false;
            // === Manage Stock Setting ===
            $current_manage = $product->get_manage_stock('edit');
            $new_manage     = !empty($this->options['inv_stock_mgt']); // true/false
            if ($current_manage !== $new_manage) {
                $product->set_manage_stock($new_manage);
                $needs_save = true;
            }
            // === Backorders ===
            $current_backorders = $product->get_backorders('edit');
            $new_backorders     = $this->options['inv_backorders'] ?? 'no';
            if ($current_backorders !== $new_backorders) {
                $product->set_backorders($new_backorders);
                $needs_save = true;
            }
            // === Regular Price ===
            if ($this->notEqual($product->get_regular_price('edit'), $item['RegularPrice'])) {
                $product->set_regular_price($item['RegularPrice']);
                $needs_save = true;
            }
            // === Sale Price ===
            if ($item['SalePrice'] !== null) {
                if ($this->notEqual($product->get_sale_price('edit'), $item['SalePrice'])) {
                    $product->set_sale_price($item['SalePrice']);
                    $needs_save = true;
                }
            } else {
                if ($product->get_sale_price('edit') !== '') {
                    $product->set_sale_price('');
                    $needs_save = true;
                }
            }
            // === Active Price (WooCommerce auto-handles this, but force sync) ===
            $active = (!empty($item['SalePrice']) && $item['SalePrice'] < $item['RegularPrice'])
                ? $item['SalePrice']
                : $item['RegularPrice'];
            $product->set_price($active);
            // === Stock Quantity & Status (Only if QtyStock is provided) ===
            if ($item['QtyStock'] !== null) {
                $qty = (int)$item['QtyStock'];
                $current_qty = $product->get_stock_quantity('edit') ?? 0;

                // Only update if quantity changed
                if ($current_qty !== $qty) {
                    // Set quantity: 0 if ≤0, otherwise the actual qty
                    $set_qty = $qty > 0 ? $qty : 0;
                    $product->set_stock_quantity($set_qty);
                    $needs_save = true;
                }
                // Set stock status based on quantity
                $new_status = $qty > 0 ? 'instock' : 'outofstock';
                if ($product->get_stock_status('edit') !== $new_status) {
                    $product->set_stock_status($new_status);
                    $needs_save = true;
                }
                // Ensure manage_stock is yes when tracking quantity
                if (!$product->get_manage_stock('edit')) {
                    $product->set_manage_stock(true);
                    $needs_save = true;
                }
            }
            // === Weight ===
            if ($item['Weight'] !== null && $this->notEqual($product->get_weight('edit'), $item['Weight'])) {
                $product->set_weight($item['Weight']);
                $needs_save = true;
            }
            // === Tiered Pricing ===
            if (!empty($item['PriceTiers']) && is_array($item['PriceTiers'])) {
                $new_tiers = $item['PriceTiers'];
                usort($new_tiers, fn($a, $b) => $a['qty'] <=> $b['qty']);

                $current_tiers = $product->get_meta('_bizuno_price_tiers', true);
                if ($current_tiers !== $new_tiers) {
                    $product->update_meta_data('_bizuno_price_tiers', $new_tiers);
                    $needs_save = true;

                }
            } else {
                if ($product->meta_exists('_bizuno_price_tiers')) {
                    $product->delete_meta_data('_bizuno_price_tiers');
                    $needs_save = true;
                }
            }
            // Save only if something changed
            if ($needs_save) {
                $product->save();
                $cnt++;
            }
        }
        if ($verbose && !empty($missingSKUs)) { \bizuno_api_msg_add("Missing in WooCommerce: " . implode(', ', array_unique($missingSKUs))); }
        return ['result' => true, 'acted' => $cnt, 'note' => "Updated $cnt of " . count($items) . " products."];
    }

    private function notEqual($a, $b) // Helper to compare floats/strings safely
    {
        if ($a === $b) { return false; }
        return (float)$a != (float)$b;
    }

    private function priceTiers(&$product, $tiers=[])
    {

        if (!empty($tiers) && is_array($tiers)) {
            // Sort by qty ascending (important!)
            usort($tiers, fn($a, $b) => $a['qty'] <=> $b['qty']);
            $current_tiers = $product->get_meta('_bizuno_price_tiers', true);
            if ($current_tiers !== $tiers) {
                $product->update_meta_data('_bizuno_price_tiers', $tiers);

            }
        } else { // No tiers sent → clear them
            if ($product->meta_exists('_bizuno_price_tiers')) { $product->delete_meta_data('_bizuno_price_tiers'); }
        }
    }

    /**
     * This method syncs the products flagged in Bizuno to be listed and the actual listed products.
     * If syncDelete flag is set, the products in the cart will be deleted if they are not on the Bizuno list
     * @return messageStack entries
     */
    public function productSync($data)
    {

        $bizSKUs = json_decode($data['syncSkus'], true); // need this if size > 1000 to avoid Apache truncation
        $wooProducts = $this->get_all_woocommerce_skus();

        $skus = array_diff($wooProducts, $bizSKUs);

        if (!empty($data['syncDelete'])) {
            foreach ($skus as $sku) {
                $post_id  = \wc_get_product_id_by_sku( $sku );
                $product  = \wc_get_product( $post_id );
                if ( !$product ) { continue; }
                $featured = $product->get_image_id();
                $galleries= $product->get_gallery_image_ids();

                if ( !empty( $featured ) ) { \wp_delete_post( $featured, true ); }
                if ( !empty( $galleries ) ) {
                    foreach( $galleries as $image ) { \wp_delete_post( $image, true ); }
                }
                \wp_delete_post($post_id, true);
            }
        }
        if (sizeof($skus) > 0) { return \bizuno_api_msg_add($this->locale['msg_sku_missing'].'Bizuno:'.'<br />'.implode(', ', $skus), 'info'); }
        \bizuno_api_msg_add($this->locale['msg_sku_sync_success'], 'success');
        return true;
    }

    /**
     * Returns a flat array of all non-empty SKUs from WooCommerce products and variations.
     * @return array<string> Array of SKU strings (e.g., ['ABC123', 'DEF-456', ...])
     */
    private function get_all_woocommerce_skus() {
        $args = [
            'limit'   => -1,                        // Fetch all
            'status'  => ['publish'],               // Only published products
            'type'    => ['simple', 'variable', 'variation'], // Include variations for their SKUs
            'return'  => 'objects',                 // Need objects to call get_sku()
        ];
        $products = wc_get_products( $args );
        $skus = [];
        foreach ( $products as $product ) {
            $sku = $product->get_sku();
            if ( '' !== $sku ) { $skus[] = $sku; }
        }
        // Remove duplicates if any (e.g., same SKU used on multiple items – rare but possible)
        return array_unique( $skus );
    }

    /**
     *
     * @param unknown $value
     * @param unknown $unit_original m,cm,mm,in,ft
     * @param unknown $unit_return m,cm,mm,in,yd
     */
    private function convertLength($value, $unit_original, $unit_return) {
        if($unit_original == $unit_return) { return $value; }
        switch ($unit_original) {
            case 'm':
                switch($unit_return) {
                    case 'cm': return $value/100;
                    case 'mm': return $value/1000;
                    case 'in': return $value*39.370;
                    case 'yd': return $value/1.0936;
                }
            case 'cm':
                switch($unit_return) {
                    case  'm': return $value*100;
                    case 'mm': return $value/10;
                    case 'in': return $value*0.39370;
                    case 'yd': return $value/109.36;
                }
            case 'mm':
                switch($unit_return) {
                    case  'm': return $value*1000;
                    case 'cm': return $value*10;
                    case 'in': return $value*0.039370;
                    case 'yd': return $value*0.039370*3*12;
                }
            case 'in':
                switch($unit_return) {
                    case  'm': return $value/39.370;
                    case 'cm': return $value/0.39370;
                    case 'mm': return $value/0.039370;
                    case 'yd': return $value*3*12;
                }
            case 'ft':
                switch($unit_return) {
                    case  'm': return $value/3.2808;
                    case 'cm': return $value/0.032808;
                    case 'mm': return $value/0.0032808;
                    case 'yd': return $value*3;
                    case 'in': return $value/12;
                }
            default:
                \bizuno_api_msg_add("length conversion Error","warning");
                return $value;
        }
    }

    /**
     *
     * @param unknown $value
     * @param unknown $unit_original kg,lb
     * @param unknown $unit_return kg,g,lb,oz
     * @return void|unknown|string|number
     */
    private function convertWeight($value, $unit_original, $unit_return) {
        if ($unit_original == $unit_return) { return $value; }
        switch ($unit_original) {
            case 'kg':
                switch($unit_return) {
                    case 'g': return $value/1000;
                    case 'lb': return $value*2.2046;
                    case 'oz': return $value*35.274;
                    default: return $value; // covers kgs
                }
            case 'lb':
                switch($unit_return) {
                    case 'kg': return $value/2.2046;
                    case 'g': return $value/0.0022046;
                    case 'oz': return $value*16;
                    default: return $value; // covers lbs unit
                }
            default:
                \bizuno_api_msg_add("Weight conversion Error, received unit $unit_original with return value: $unit_return", 'Warning');
                return $value;
        }
    }
}
