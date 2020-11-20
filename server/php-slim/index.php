<?php
use Slim\Http\Request;
use Slim\Http\Response;
use Stripe\Stripe;

require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

require './config.php';

$app = new \Slim\App;

// Instantiate the logger as a dependency
$container = $app->getContainer();
$container['logger'] = function ($c) {
  $settings = $c->get('settings')['logger'];
  $logger = new Monolog\Logger($settings['name']);
  $logger->pushProcessor(new Monolog\Processor\UidProcessor());
  $logger->pushHandler(new Monolog\Handler\StreamHandler(__DIR__ . '/logs/app.log', \Monolog\Logger::DEBUG));
  return $logger;
};

$app->add(function ($request, $response, $next) {
    Stripe::setApiKey(getenv('STRIPE_SECRET_KEY'));
    return $next($request, $response);
});

$app->get('/', function (Request $request, Response $response, array $args) {
    return $response->write(file_get_contents(getenv('STATIC_DIR') . '/index.html'));
});

$app->get('/config', function (Request $request, Response $response, array $args) {
  $pub_key = getenv('STRIPE_PUBLISHABLE_KEY');

  return $response->withJson([
    'publicKey' => $pub_key,
    'unitAmount' => 2900,
    'currency' => 'EUR'
  ]);
});



$app->get('/charge-card-off-session', function(Request $request, Response $response, array $args) {
    $customer_id = $request->getQueryParam("customerId");

    try {

        // List the Customer's PaymentMethods to pick one to pay with
        $payment_methods = \Stripe\PaymentMethod::all([
            'customer' => $customer_id,
            'type' => 'card'
        ]);

        // Create a PaymentIntent with the order amount, currency, and saved payment method ID
        // If authentication is required or the card is declined, Stripe
        // will throw an error
        $payment_intent = \Stripe\PaymentIntent::create([
            'amount' => 2900,
            'currency' => "EUR",
            'payment_method' => $payment_methods->data[0]->id,
            'customer' => $customer_id,
            'confirm' => true,
            'off_session' => true
        ]);

        // Send public key and PaymentIntent details to client
        return $response->withJson(array('succeeded' => true, 'clientSecret' => $payment_intent->client_secret));

    } catch (\Stripe\Exception\CardException $err) {
        $error_code = $err->getError()->code;

        if($error_code == 'authentication_required') {
            // Bring the customer back on-session to authenticate the purchase
            // You can do this by sending an email or app notification to let them know
            // the off-session purchase failed
            // Use the PM ID and client_secret to authenticate the purchase
            // without asking your customers to re-enter their details
            return $response->withJson(array(
                'error' => 'authentication_required',
                'card'=> $err->getError()->payment_method->card,
                'paymentMethod' => $err->getError()->payment_method->id,
                'clientSecret' => $err->getError()->payment_intent->client_secret
            ));

        } else if ($error_code && $err->getError()->payment_intent != null) {
            // The card was declined for other reasons (e.g. insufficient funds)
            // Bring the customer back on-session to ask them for a new payment method
            return $response->withJson(array(
                'error' => $error_code ,
                'clientSecret' => $err->getError()->payment_intent->client_secret
            ));
        } else {
            $logger = $this->get('logger');
            $logger->info('Unknown error occurred');
        }
    }

});



$app->post('/create-checkout-session', function(Request $request, Response $response, array $args) {
  $domain_url = getenv('DOMAIN');
  $body = json_decode($request->getBody());

  $quantity = $body->quantity;

  $customer = \Stripe\Customer::create([
      'email' => 'client@server.com'
  ]);

    // Create new Checkout Session for the order
  // Other optional params include:
  // [billing_address_collection] - to display billing address details on the page
  // [customer] - if you have an existing Stripe Customer ID
  // [payment_intent_data] - lets capture the payment later
  // [customer_email] - lets you prefill the email input in the form
  // For full details see https://stripe.com/docs/api/checkout/sessions/create

  // ?session_id={CHECKOUT_SESSION_ID} means the redirect will have the session ID set as a query param
  $checkout_session = \Stripe\Checkout\Session::create([
    'success_url' => $domain_url . '/success.html?customerId='.$customer->id,
    'cancel_url' => $domain_url . '/canceled.html',
    'payment_method_types' => ['card'],
    'mode' => 'setup',
    'customer' => $customer->id
  ]);

  return $response->withJson(array('sessionId' => $checkout_session['id']));
});

$app->post('/webhook', function(Request $request, Response $response) {
    $logger = $this->get('logger');
    $event = $request->getParsedBody();
    // Parse the message body (and check the signature if possible)
    $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
    if ($webhookSecret) {
      try {
        $event = \Stripe\Webhook::constructEvent(
          $request->getBody(),
          $request->getHeaderLine('stripe-signature'),
          $webhookSecret
        );
      } catch (\Exception $e) {
        return $response->withJson([ 'error' => $e->getMessage() ])->withStatus(403);
      }
    } else {
      $event = $request->getParsedBody();
    }
    $type = $event['type'];
    $object = $event['data']['object'];

    if($type == 'checkout.session.completed') {
      $logger->info('🔔  Payment succeeded! ');
    }

    return $response->withJson([ 'status' => 'success' ])->withStatus(200);
});

$app->run();
