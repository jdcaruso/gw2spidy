<?php

use GW2Spidy\Application;
use GW2Spidy\DB\ItemQuery;
use GW2Spidy\DB\ItemTypeQuery;
use GW2Spidy\DB\ListingQuery;
use GW2Spidy\DB\WorkerQueueItemQuery;

require dirname(__FILE__) . '/../config/config.inc.php';
require dirname(__FILE__) . '/../autoload.php';

$app = new Application();
$app['debug'] = true;

$toInt = function($val) {
    return (int) $val;
};

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => dirname(__FILE__) . '/../templates',
));

/**
 * ----------------------
 *  route /
 * ----------------------
 */
$app->get("/", function() use($app) {
    $app->setHomeActive();
    $featured = ItemQuery::create()->findPk(1140);

    return $app['twig']->render('index.html.twig', array(
        'featured' => $featured,
    ));
});

/**
 * ----------------------
 *  route /types
 * ----------------------
 */
$app->get("/types", function() use($app) {
    $types = ItemTypeQuery::create()
    ->orderByTitle()
    ->find();

    return $app['twig']->render('types.html.twig', array(
            'types' => $types,
    ));
});

/**
 * ----------------------
 *  route /type
 * ----------------------
 */
$app->get("/type/{type}/{subtype}/{page}", function($type, $subtype, $page) use($app) {
    $itemsperpage = 25;
    $baseurl      = "/type/{$type}/{$subtype}";
    $q            = ItemQuery::create();

    if (!is_null($type)) {
        $q->filterByItemTypeId($type);
    }
    if (!is_null($subtype)) {
        $q->filterByItemSubTypeId($subtype);
    }

    $count    = $q->count();
    $lastpage = ceil($count / $itemsperpage);
    if ($page > $lastpage) {
        $page = $lastpage;
    }

    $items = $q->offset($itemsperpage * $page)
                    ->orderBy("Name", "ASC")
                    ->limit($itemsperpage)
                    ->find();

    return $app['twig']->render('type.html.twig', array(
            'page'     => $page,
            'lastpage' => $lastpage,
            'items'    => $items,
            'baseurl'  => $baseurl,
    ));
})
->assert('type',     '\d+')
->assert('subtype',  '\d+')
->assert('page',     '\d+')
->convert('type',    $toInt)
->convert('subtype', $toInt)
->convert('page',    $toInt)
->value('type',      null)
->value('subtype',   null)
->value('page',      1);

/**
 * ----------------------
 *  route /item
 * ----------------------
 */
$app->get("/item/{dataId}", function($dataId) use ($app) {
    $item = ItemQuery::create()->findPK($dataId);

    return $app['twig']->render('item.html.twig', array(
        'item'        => $item,
    ));
})
->assert('dataId',  '\d+')
->convert('dataId', $toInt);

/**
 * ----------------------
 *  route /chart
 * ----------------------
 */
$app->get("/chart/{dataId}", function($dataId) use ($app) {
    $item = ItemQuery::create()->findPK($dataId);

    $chart   = array();

    $dataset = array(
        'data'   => array(),
        'label'  => $item->getName(),
    );

    ///*
    $con   = Propel::getConnection();
    $query = "
    SELECT
        listing_date,
        listing_time,
        SUM(unit_price * quantity) / SUM(quantity) as avgunitprice
    FROM (
        SELECT
            item_id,
            listing_date,
            listing_time,
            CAST(
                SUBSTRING_INDEX(
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(unit_price ORDER BY unit_price ASC),
                    ',', value),
                ',', -1)
             AS UNSIGNED) AS unit_price,
        CAST(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                    GROUP_CONCAT(quantity ORDER BY unit_price ASC),
                ',', value),
            ',', -1)
        AS UNSIGNED) AS quantity
        FROM
            listing, tinyint_asc
        WHERE
            item_id = 4496
        AND
            tinyint_asc.value >= 1 AND tinyint_asc.value <= 10
        GROUP BY
            listing_date, listing_time, value
    ) listing
    GROUP BY
        listing_date, listing_time
    ORDER BY listing_date ASC, listing_time ASC
    ";

    $stmt = $con->prepare($query);
    $stmt->execute();

    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    //*/

    /*

    $listings = ListingQuery::create()
                ->select(array('listing_date', 'listing_time'))
                ->leftJoin('tinyint_asc')
                ->withColumn("CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( GROUP_CONCAT(unit_price ORDER BY unit_price ASC), ',', value), ',', -1) AS UNSIGNED)",
                                    'unit_price')
                ->withColumn("CAST( SUBSTRING_INDEX( SUBSTRING_INDEX( GROUP_CONCAT(quantity ORDER BY unit_price ASC), ',', value), ',', -1) AS UNSIGNED)",
                                    'quantity')
                ->where('tinyint_asc.value', 1, ListingQuery::GREATER_EQUAL)
                ->where('tinyint_asc.value', 10, ListingQuery::LESS_EQUAL)
                ->groupBy('listing_date')
                ->groupBy('listing_time')
                ->groupBy('tinyint_asc.value')
                ->filterByItemId($item->getDataId());


    var_dump($listings->find());
    die();
    //*/
    /*
    $res      = ListingQuery::create()
                ->addSelectQuery($listing, 'last_10_listing')
                ->find()

    ->groupByItemId()
    ->groupByListingDate()
    ->groupByListingTime()
    ->orderByListingDate('asc')
    ->orderByListingTime('asc')
    ->withColumn('SUM(unit_price * quantity) / SUM(quantity)', 'avgunitprice');
    //*/

    foreach ($res as $listingEntry) {
        $date = new DateTime("{$listingEntry['listing_date']} {$listingEntry['listing_time']}");

        $listingEntry['avgunitprice'] = round($listingEntry['avgunitprice'], 2);

        $dataset['data'][] = array($date->getTimestamp()*1000, $listingEntry['avgunitprice']);
    }

    $chart[] = $dataset;

    $wrap    = false;
    $content = json_encode($chart);

    return $content;
})
->assert('dataId',  '\d+')
->convert('dataId', $toInt);

/**
 * ----------------------
 *  route /status
 * ----------------------
 */
$app->get("/status", function() use($app) {
    $res = WorkerQueueItemQuery::create()
    ->withColumn('COUNT(*)', 'Count')
    ->select(array('Status', 'Count'))
    ->groupByStatus()
    ->find();

    ob_start();
    foreach ($res as $statusCount) {
        var_dump($statusCount);
    }

    $content = ob_get_clean();

    return $app['twig']->render('dump.html.twig', array(
            'dump' => $content,
    ));
});

/**
 * ----------------------
 *  route /search
 * ----------------------
 */
$app->get("/search", function() use($app) {
    return $app['twig']->render('search.html.twig', array());
});

$app->run();

?>
