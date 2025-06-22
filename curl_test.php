<?php
$ch = curl_init("https://backend.flexpay.cd/api/rest/v1/paymentService");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$result = curl_exec($ch);
if($result === false) {
    echo 'Erreur cURL : ' . curl_error($ch);
} else {
    echo 'Réponse serveur : ' . $result;
}
curl_close($ch);
