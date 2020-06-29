<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', 'OrderController@getTowarFromZk');
Route::get('/export', 'OrderController@getTowarFromZkExport');
Route::get('/komplet/{id}', 'OrderController@getKomplet');
Route::get('/product/{id}', 'OrderController@getTowar');
Route::get('/product/{id}/cena', 'OrderController@getCena');
Route::get('/dokument/{id}', 'OrderController@getDokument');
Route::get('/pozycja/{id}', 'OrderController@getPozycja');

Route::get('/addpw/{prodId}/{quantity}', 'OrderController@addPw');
Route::get('/connectpwrw/{PwId}/{RwId}', 'OrderController@connectPwRw');

Route::get('/product', 'OrderController@searchTowar');
Route::get('/product/stan/{id}/{quantity}', 'OrderController@checkQuantity');


Route::get('/dok_last_id/{db_name}','OrderController@getLastId');
Route::get('/get_mag_ruch/{prod_id}','OrderController@getMagRuch');


Route::get('/test','OrderController@test');
