<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * 職員間の連絡（メッセージ）。
 *
 * 下のバーの並びを先に決め、中身はあとから作る。並びが変わると、
 * 職員は押す場所を覚え直すことになる。それまでは準備中であることを伝える。
 */
class MessageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('messages/index');
    }
}
