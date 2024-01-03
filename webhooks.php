<?php

//require 'vendor/autoload.php';
require 'lib/init.php';
require 'process.php';

$stripe = new \Stripe\StripeClient('sk_test_51ORIm3HjL6v6bboqXhlcTGHCvDbKoWIjXs48Ao2OR4MjAC6KnLOtNK9rfhWDFW6Mg9YIt8LBG8kROhXHGpkQAsX5001bpRBsE6');

// This is your Stripe CLI webhook secret for testing your endpoint locally.
$endpoint_secret = 'whsec_c45f2324d5b54e83bbfda525b2f316498725aa44291029ccffe4647075cad9ca';

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;

try {
$event = \Stripe\Webhook::constructEvent(
    $payload, $sig_header, $endpoint_secret
);
error_log ('Event: '.$event);
} catch(\UnexpectedValueException $e) {
// Invalid payload
http_response_code(400);
exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
// Invalid signature
http_response_code(400);
exit();
}

switch ($event->type) {
  case 'customer.created':
    // save event with customer
    try {
      $customer_id = $event->data->object->customer;
      $customer = \Stripe\Customer::retrieve($customer_id);
      if ($customer) {
          $new_event = Model::factory('Event')->create();
          $new_event->stripe_customer_id = $customer_id;
          $new_event->customer_name = $customer->name;
          $new_event->customer_email = $customer->email;
          $new_event->type = $event->type;
          $new_event->event_id = $event->id;
          $new_event->save();
      }
    } catch (\Exception $e) {
      error_log('Error:' . $e);
      //http_response_code(400);
      exit();
    }
  case 'customer.subscription.updated':
      // change the subscription status
      $subscription = $event->data->object;
      $query = Model::factory('Subscription');
      $sub_model = $query->where_equal('stripe_subscription_id', $subscription->id)->find_one();

      if ($sub_model) {
          $sub_model->status = $subscription->status;
          $sub_model->save();
      }
      break;
  case 'payment_intent.succeeded':
      $paymentIntent = $event->data->object;

      break;
  case 'invoice.payment_succeeded':
      $invoice = $event->data->object;
      $customer = \Stripe\Customer::retrieve($invoice->customer);
      $subject = 'Your payment has been received';
      $headers = 'From: ' . $config['email'];

      $values = array(
          'customer_name' => $customer->name,
          'customer_email' => $customer->email,
          'amount' => currency($invoice->amount_paid) . '<small>' . currencySuffix() . '</small>',
          'description_title' => 'Description',
          'description' => $invoice->description,
          'payment_method' => 'Credit Card',
          'url' => url(''),
      );

      email($customer->email, $subject, $values, $headers);
      break;
  default:
    echo 'Received unknown event type ' . $event->type;
}

http_response_code(200);

require 'lib/close.php';