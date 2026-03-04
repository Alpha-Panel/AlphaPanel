<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class ManifestController extends Controller
{
    public function index(): Response
    {
        return response()->view('manifest')->header('Content-Type', 'application/json');
    }
}
