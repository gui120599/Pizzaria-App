<?php

namespace App\Http\Controllers;

use App\Models\Mesa;
use Illuminate\Http\Request;

class Dashboard extends Controller
{
    public function index()
    {
        $mesas = Mesa::all(); // Supondo que você tenha um modelo Mesa

        return view('dashboard', compact('mesas'));
    }
}
