<?php

class shopify_api_orders {
  private $shopify;
  public function __construct($shopify)
  {
    $this->shopify = $shopify;
  }

 //required access_scopes : read_orders, read_merchant_managed_fulfillment_orders
 public function get_orders($params = array())
 {
   //sugest only using 'fulfillment_status', 'financial_status', 'id', 'created_at', 'updated_at', 'processed_at' though others are documented
   // https://shopify.dev/docs/api/admin-graphql/2024-04/queries/orders#argument-query
   // other potential fields are : default cart_token channel channel_id chargeback_status checkout_token confirmation_number credit_card_last4 customer_id delivery_method discount_code email fraud_protection_level fulfillment_location_id fulfillment_status gateway location_id name payment_id payment_provider_id po_number reference_location_id return_status risk_level sales_channel source_identifier source_name tag tag_not test
   // fields with "_at" are interpreted as datetimes and translated by strtotime. The must end with _gte or _lte (greater than or less than)
   $query_filter_keys = array(
     'fulfillment_status',
     'financial_status',
     'id',
     'created_at_gte',
      'created_at_lte',
     'updated_at_gte',
     'updated_at_lte',
     'processed_at_gte',
     'processed_at_lte',
     'sku',
     'status',
   );


   if (empty($params)) {
     $params['fulfillment_status'] = 'unfulfilled';
     $params['financial_status'] = 'paid';
   }


   $query_filter = array();
   foreach ($params as $k => $v) {
     if (in_array($k, $query_filter_keys)) {
       if (false !== strpos($k, '_at')) {
         $v = gmdate('Y-m-d\TH:i:s\Z', strtotime($v));
       }
       if (false !== strpos($k, '_gte')) {
         $k = str_replace('_gte', '', $k);
         $query_filter[] = "$k:>=$v";
       } elseif (false !== strpos($k, '_lte')) {
         $k = str_replace('_gte', '', $k);
         $query_filter[] = "$k:<=$v";
       } else {
         $query_filter[] = "$k:$v";
       }
     }
   }
   $query_filter = implode(" AND ", $query_filter);

   $query = '
query GetOrders ($order_cursor: String, $fulfillment_order_cursor: String, $line_item_cursor: String)
{
   orders(first: 250, query: "' . $query_filter . '", after: $order_cursor) {
       edges {
           cursor
           node {
               id
               createdAt
               updatedAt
               displayFinancialStatus
               currentTotalWeight
               poNumber
               netPaymentSet {
                   shopMoney {
                       amount
                   }
               }
               email
               billingAddress {
                   address1
                   address2
                   city
                   company
                   countryCodeV2
                   firstName
                   lastName
                   name
                   phone
                   province
                   zip
               }
               displayFinancialStatus
               note
               shippingLine {
                   carrierIdentifier
                   code
                   requestedFulfillmentService {
                       serviceName
                   }
               }
               shippingAddress {
                   address1
                   address2
                   city
                   company
                   countryCodeV2
                   firstName
                   lastName
                   name
                   phone
                   province
                   zip
               }
               fulfillmentOrders(first: 3, after: $fulfillment_order_cursor) {
                   pageInfo {
                       hasNextPage
                       endCursor
                       startCursor
                   }
                   nodes {
                       lineItems(first: 50, after: $line_item_cursor) {
                           pageInfo {
                               hasNextPage
                               endCursor
                               startCursor
                           }
                           nodes {
                               weight {
                                   value
                                   unit
                               }
                               lineItem {
                                   id
                                   name
                                   sku
                                   requiresShipping
                                   quantity
                                   originalUnitPriceSet {
                                       shopMoney {
                                           amount
                                       }
                                   }
                                   taxLines {
                                       priceSet {
                                           shopMoney {
                                               amount
                                           }
                                       }
                                   }
                                   discountedTotalSet {
                                       shopMoney {
                                           amount
                                       }
                                   }
                                   image {
                                       originalSrc
                                   }
                               }
                           }
                       }
                   }
               }
           }
       }
       pageInfo {
           hasNextPage
           endCursor
           startCursor
       }
   }
}
';
   $order_list = array();
   $orders = array();
   $count = 0;
   $pending_cursors = array(array(
     "order_cursor" => null,
     "fulfillment_order_cursor" => null,
     "line_item_cursor" => null,
   ));
   do {
     $variables = array_shift($pending_cursors);
     $result = $this->shopify->GraphQL->post($query, null, null, $variables);
     $order_page_info = $result['data']['orders']['pageInfo'];
     $next_order_cursor = ($order_page_info['hasNextPage']) ? $order_page_info['endCursor'] : false;
     $nodes = $result['data']['orders']['edges'];
     foreach ($nodes as $node) {
       $order = $node['node'];
       $line_items = array();
       foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment_order) {
         foreach ($fulfillment_order['lineItems']['nodes'] as $line_item) {
           $line_items[] = $line_item;
         }
       }
       if (empty($orders[$order['id']])) {
         $orders[$order['id']] = $order;
       } else {
         $existing_line_item_ids = array();
         foreach ($orders[$order['id']]['fulfillmentOrders']['nodes'][0]['lineItems']['nodes'] as $line_item) {
           $existing_line_item_ids[] = $line_item['lineItem']['id'];
         }
         foreach ($line_items as $line_item) {
           if (!in_array($line_item['lineItem']['id'], $existing_line_item_ids)) {
             $orders[$order['id']]['fulfillmentOrders']['nodes'][0]['lineItems']['nodes'][] = $line_item;
           }
         }
       }


       $next_fullfillment_order_cursor = ($order['fulfillmentOrders']['pageInfo']['hasNextPage']) ? $order['fulfillmentOrders']['pageInfo']['endCursor'] : false;
       foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment_order) {
         $next_line_item_cursor = ($fulfillment_order['lineItems']['pageInfo']['hasNextPage']) ? $fulfillment_order['lineItems']['pageInfo']['endCursor'] : false;
         if (!empty($next_line_item_cursor)) {
           $pending_cursors[] = array(
             "order_cursor" => $variables["order_cursor"],
             "fulfillment_order_cursor" => $variables["fulfillment_order_cursor"],
             "line_item_cursor" => $next_line_item_cursor,
           );
         }
       }
       if (!empty($next_fullfillment_order_cursor)) {
         $pending_cursors[] = array(
           "order_cursor" => $variables["order_cursor"],
           "fulfillment_order_cursor" => $next_fullfillment_order_cursor,
           "line_item_cursor" => null,
         );
       }
     }
     if (!empty($next_order_cursor)) {
       $pending_cursors[] = array(
         "order_cursor" => $next_order_cursor,
         "fulfillment_order_cursor" => null,
         "line_item_cursor" => null,
       );
     }

     if ($count++ > 100) {
       throw new \Exception("Too many pages of unfulfilled orders found. Please contact support for assistance.");
     }
   } while (count($pending_cursors) > 0);
   return $orders;
 }

}
