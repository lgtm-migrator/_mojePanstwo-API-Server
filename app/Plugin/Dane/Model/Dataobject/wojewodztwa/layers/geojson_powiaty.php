<?php
/**
* Zwraca obiekt GeoJson FeatureCollection zawierający wszystkie gminy tego powiatu (właściwości są keszowane także)
*/

App::import('model', 'MPCache');
App::uses('Model', 'Dane.Dataobject');

// Try cache
$cacheKey = 'geojson/agg/wojewodztwo/' . $id . '/powiaty';

$cache = new MPCache();
$cacheClient = $cache->getDataSource()->getRedisClient();
if ($cacheClient->exists($cacheKey)) {
    return json_decode($cache->get($cacheKey));

} else {
    // Build geojson feature collection
    $powiaty_ids = $this->DB->selectValues("SELECT id FROM epf.pl_powiaty WHERE w_id = $id AND akcept = '1'");

    if (!$powiaty_ids) {
        throw new Exception("Nie znaleziono powiatów dla w_id = $id");
    }

    $powiaty = array();
    foreach($powiaty_ids as $pid) {
        $d = new Dataobject();
        $powiaty[] = $d->getObjectLayer('powiaty', $pid, 'geojson', $params = array());
    }

    $featc = array(
        "type" => "FeatureCollection",
        "features" => $powiaty
    );

    // Put in cache
    $cacheClient->set($cacheKey, json_encode($featc), 'EX', 3600 * 24 * 7);

    return $featc;
}