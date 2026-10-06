<?php

use Illuminate\Support\Facades\Route;
use Workdo\Notes\Http\Controllers\NoteController;
use Workdo\Notes\Http\Controllers\NotebookController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Notes'])->group(function () {
    Route::resource('notes/notes', NoteController::class)->only(['index', 'store', 'update', 'destroy'])->names('notes.notes');
    Route::resource('notes/notebooks', NotebookController::class)->only(['index', 'store', 'update', 'destroy'])->names('notes.notebooks');
    // <crud-routes>
});
