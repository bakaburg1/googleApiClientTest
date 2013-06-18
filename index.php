<?php

// Generica funzione di logging su console
function sism_debug($var){

    $result = var_export( $var, true );

	$trace = debug_backtrace();
	$level = 1;
	@$file = $trace[$level]['file'];
	@$line = $trace[$level]['line'];
	@$object = $trace[$level]['object'];
	if (is_object($object)) { $object = get_class($object); }

	error_log("Line $line ".($object?"of object $object":"")."(in $file):\n$result");
}

// Permette di elencare i file sul google drive connesso.
function retrieveAllFiles($client) {

	require_once "google-api-php-client/src/contrib/Google_DriveService.php";

	$service = Google_DriveService($client);

	$result = array();
	$pageToken = NULL;

	do {
		try {
		  $parameters = array();
		  if ($pageToken) {
			$parameters['pageToken'] = $pageToken;
		  }
		  $files = $service->files->listFiles($parameters);

		  $result = array_merge($result, $files->getItems());
		  $pageToken = $files->getNextPageToken();
		} catch (Exception $e) {
		  error_log("An error occurred: " . $e->getMessage());
		  $pageToken = NULL;
		}
	} while ($pageToken);
	return $result;
}

// Invece del database in questo esempio usiamo la variabile $_SESSION per salvare i dati
session_start();

error_log("What's in the SESSION global?");
sism_debug(@$_SESSION['access_token']);

// Immagazzina gli access token (è un'array di roba) in una variabile globale
global $access_token;
$access_token = @$_SESSION['access_token'] || false;

// Fa partire la cosiddetta oAuth dance
function init(){

	// Richiama la libreria di Google
	require_once 'google-api-php-client/src/Google_Client.php';

	// Crea un oggetto per immagazzinare i dati inseriti nel form. Qui andrebbero usate le funzioni di sanitizzazione degli input di WP
	$app_data = array();

	@$app_data['client_ID'] = $_POST['client_ID'];
	@$app_data['client_secret'] = $_POST['client_secret'];
	@$app_data['scopes'] = explode("\n",trim($_POST['scopes']));
	@$app_data['redirect_url'] = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']; // come redirect usiamo l'url attuale

	// Confronta i nuovi dati con quelli salvati nel database
	if($_SESSION['app_data'] !== $app_data){
		$_SESSION['app_data'] = $app_data; //Salva i dati nel database. Nella vera applicazione ci serviranno per l'accesso offline a google drive
	}

	// Crea un google client; viene utilizzato per gestire la comunicazione con google)
	$client = new Google_Client();
	$client->setClientId($app_data['client_ID']);
	$client->setClientSecret($app_data['client_secret']);
	$client->setRedirectUri($app_data['redirect_url']);
	$client->setScopes($app_data['scopes']);
	$client->setAccessType('offline');

	//Numero random di sicurezza per essere sicuri che i messaggi che ci arrivano da google sono stati richiesti da noi. In WP si usa wp_nonce()
	if(!isset($_SESSION['security']) OR $_SESSION['security'] == ""){
		$_SESSION['security'] = md5(rand());
	}

	$state = $_SESSION['security'];

	// Il codice di sicurezza viene registrato nel client per essere mandato a google
	$client->setState($state);

	error_log('Client Set!');

	// Se è presente il codice allora viene chiesto un access token
	if (isset($_GET['code']) AND $_GET['state'] == $state AND (!isset($access_token) OR !$access_token))
	{
		error_log('Code arrived!');
		sism_debug($_GET);

		error_log('Now authenticating...');
		$result = $client->authenticate();
		sism_debug($result);
		$access_token = $_SESSION['access_token'] = $client->getAccessToken();
		sism_debug($access_token);
	} // Se non abbiamo ne codice, ne access token, allora manda l'utente alla pagina per dare il permesso
	elseif (!isset($access_token) OR !$access_token)
	{
		error_log("Creating authUrl...");
		$authUrl = $client->createAuthUrl();
		sism_debug($authUrl);
		header('Location: ' . $authUrl);
		exit();
	}
	elseif (isset($access_token) AND $access_token) // Se abbiamo il token invece...
	{
		if (isset($_POST['logout']) AND $_POST['logout'] == "true") // Se richiesto il logout cancellare i dati registrati
		{
			error_log('Logging out!');
			$client->setAccessToken($access_token);
			$client->revokeToken();
			session_destroy();
		}
		else // Altrimenti registrare l'access token nel client ed utilizzarlo per accedere ai servizi di google
		{
			error_log('Setting up access token...');
			$client->setAccessToken($access_token);
		}
	}

	if ($client->getAccessToken()) { // Abbiamo l'access token ed è presente nel client! Ora il client può essere usato per utilizzare google drive!
		error_log('Fetching Results');
		sism_debug(retrieveAllFiles($client));
	}
}

//Controlla che la pagina sia stata chiamata tramite un Submit del form o redirect di google e non per semplice apertura dell'url
if( (@isset($_POST['manual_submit']) AND @$_POST['manual_submit']) OR (@isset($_GET['code']) AND @$_GET['code'])){
	error_log('Manual submit or google redirect! Initialiazing...');
	init();
}
elseif (@isset($_POST['error'])){ // Gestisce errori nella richiesta del codice di accesso
	error_log('Something got wrong...');
	sism_debug($_POST['error']);
}

error_log('Rendering page...');
?>


<!DOCTYPE html>

<html>
<head>
	<meta charset="utf-8">
	<title>GoogleApiClient</title>
	<style type="text/css">
		.hidden {
			display: none;
		}
	</style>
</head>

<body>
<h1>Google Api Client</h1>
<p>L'applicazione proverà ad accedere ai tuoi dati</p>
<a href="http://<?php echo $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] ?>">link alla pagina</a>
<form action="index.php" method="POST">
	<p>
		<label>Client ID</label><br>
		<input type="text" name="client_ID"/>
	</p>
	<p>
		<label>Client Secret</label><br>
		<input type="text" name="client_secret"/>
	</p>
	<p>
		<label>Scopes</label><br>
		<textarea name="scopes"></textarea>
	</p>
	<input type="hidden" name="manual_submit" value="true"/>
	<?php if(!isset($access_token) OR $access_token == ""){?>
	<input type="submit" value="Autorizza"/>
	<input type="hidden" name="auth_action" value="authorize"/>
	<?php }
	else{ ?>
	<input type="submit" value="Revoca"/>
	<input type="hidden" name="auth_action" value="logout"/>
	<?php } ?>
</form>

<script src="http://code.jquery.com/jquery-2.0.0.min.js"></script>
<script type="application/x-javascript">
	$(document).ready(function() {
		$("input:text, textarea").each(function(){
			$(this).val(localStorage[$(this).attr('name')]);
		})

		$("form").submit(function(){
			console.log("form submit!");
			$("input:text, textarea").each(function(){
				localStorage[$(this).attr('name')] = $(this).val();
			})
		});
	});
</script>
</body>
</html>
