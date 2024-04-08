<?php
// Check if the API key is specified in the URL
if (isset($_GET['api'])) {
    // Retrieve the API key from the URL
    $api_key = $_GET['api'];
} else {
    // Return an error message if the API key is not specified in the URL
    echo "API key is required.";
    exit();
}

// Require database connection
require_once "/var/www/dashboard/db_connect.php";

// Prepare the SQL statement to retrieve the channel name and username for the given API key
$stmt = $conn->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->bind_param("s", $api_key);

// Execute the SQL statement
$stmt->execute();

// Bind the result to variables
$stmt->bind_result($channelname);

// Fetch the result
$stmt->fetch();

// Close the statement
$stmt->close();

// Close the database connection
$conn->close();

// Check if the provided API key is valid and retrieve the channel name from the database
if (empty($channelname)) {
    // Return an error message if the API key is not valid
    echo "Invalid API key.";
    exit();
}

// Jokes API URL
$jokesApiUrl = "https://v2.jokeapi.dev/joke/Programming,Miscellaneous,Pun,Spooky,Christmas?blacklistFlags=nsfw,religious,political,racist,sexist,explicit";

// Initialize a new cURL session for jokes API
$curl = curl_init($jokesApiUrl);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

// Execute the cURL session
$response = curl_exec($curl);

// Check for errors
if (curl_errno($curl)) {
    echo "Error: " . curl_error($curl);
    exit();
}

// Close the cURL session
curl_close($curl);

// Decode the JSON response
$data = json_decode($response, true);

// Check if the joke type is present in the $data array
if (!isset($data['type'])) {
    // Return an error message if the joke type is not present
    echo "Error: Unable to retrieve joke from API.";
    exit();
}

// Get the joke based on the type
if ($data['type'] == 'single') {
    $joke = $data['joke'];
} elseif ($data['type'] == 'twopart') {
    $setup = $data['setup'];
    $delivery = $data['delivery'];
    $joke = $setup . "\n" . $delivery;
} else {
    echo "Error: Invalid joke type.";
    exit();
}

// Output the joke
echo $joke;
?>