<?php

/**
 * Image model testing.
 */

 require_once 'image.php';

try {
    $image = new Image(
        1,
        "Image title here",
        'image1.jpg',
        "image/jpeg",
        3
    );

    header('Content-type: application/json;charset=UTF-8');

    echo json_encode($image->returnImageAsArray());

} catch (ImageException $ie) {
    echo "error: ".$ie->getMessage();
}