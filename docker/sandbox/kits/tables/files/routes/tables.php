<?php

use App\Http\Controllers\Tables\ActivityController;
use App\Http\Controllers\Tables\AttachmentController;
use App\Http\Controllers\Tables\FieldController;
use App\Http\Controllers\Tables\FormulaController;
use App\Http\Controllers\Tables\RecordController;
use App\Http\Controllers\Tables\TableController;
use App\Http\Controllers\Tables\ViewController;
use Illuminate\Support\Facades\Route;

/*
| The table kit's endpoints, loaded by TablesServiceProvider under the web and auth middleware,
| config('tables.prefix') and the "tables." name prefix.
*/

Route::get('files/{table}/{path}', [AttachmentController::class, 'show'])->where('path', '.*')->name('files');

Route::get('{table}', [TableController::class, 'show'])->name('show');

Route::get('{table}/records', [RecordController::class, 'index'])->name('records.index');
Route::post('{table}/records', [RecordController::class, 'store'])->name('records.store');
Route::get('{table}/records/{record}/activity', [ActivityController::class, 'index'])->whereNumber('record')->name('activity');
Route::post('{table}/records/{record}/comments', [ActivityController::class, 'comment'])->whereNumber('record')->name('comments.store');
Route::delete('{table}/comments/{comment}', [ActivityController::class, 'destroyComment'])->whereNumber('comment')->name('comments.destroy');

Route::post('{table}/fields', [FieldController::class, 'store'])->name('fields.store');
Route::patch('{table}/fields/{field}', [FieldController::class, 'update'])->name('fields.update');
Route::delete('{table}/fields/{field}', [FieldController::class, 'destroy'])->name('fields.destroy');
Route::post('{table}/fields/{field}/duplicate', [FieldController::class, 'duplicate'])->name('fields.duplicate');

Route::post('{table}/formula', [FormulaController::class, 'preview'])->name('formula');

Route::post('{table}/views', [ViewController::class, 'store'])->name('views.store');
Route::post('{table}/views/order', [ViewController::class, 'order'])->name('views.order');
Route::patch('{table}/views/{view}', [ViewController::class, 'update'])->whereNumber('view')->name('views.update');
Route::delete('{table}/views/{view}', [ViewController::class, 'destroy'])->whereNumber('view')->name('views.destroy');

Route::post('{table}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
