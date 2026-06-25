<?php
/* Shared Dunhill client list (from Dunhill_Clients.xlsx). Edit here to add/remove. */
function audrey_client_list() {
    return [
      'Avacado Investments','Avacado Investments (2)','Birchwood Villas','Bishop Properties',
      'Broadway Enterprises Limited','Chasewood Apartments','Citadel','Delta Riverside Management Co. Ltd',
      'Dunhill Towers','Elgon Management Company Limited','Empress Office Suites','Fairacres Development Ltd C/o Dunhill',
      'Krishna Residency','Krystal Investment Ltd','Kusi Lane Management - Royal Apartments',
      'Laxmi Plaza','Magnolia','North Park Management Ltd - Park Place','Office Suites',
      'Oz Management (Citadel)','Parque Management Limited','Royal Building Management Ltd','Ruaraka Business Park',
      'Sage Jiva Management Ltd','Samar Gardens','Samar Heights','Sandalwood Brookside',
      'Savanna Business Park','Savannah','Sports Road Furnished Apartments','Taarifa Gardens Holding Ltd',
      'The Promanade','The Residences','The Residences (2)','Tree Tops Apartments',
    ];
}
function audrey_norm($s){ return strtolower(trim(preg_replace('/\s+/', ' ', (string)$s))); }
function audrey_client_set() {
    $set = [];
    foreach (audrey_client_list() as $n) $set[audrey_norm($n)] = $n;
    return $set;
}
