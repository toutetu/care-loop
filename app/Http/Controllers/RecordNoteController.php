<?php

namespace App\Http\Controllers;

use App\Enums\NoteInputMethod;
use App\Http\Requests\StoreRecordNoteRequest;
use App\Models\ServiceRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 音声入力・手入力の原文を確定する。
 *
 * 【確定とAIの書き直しを分けた理由】
 * 以前はAIの変換ボタンが、入力欄の内容の保存とAIの呼び出しを一度に行っていた。
 * 音声の聞き違いを直す前に押してしまうと、直していない文が原文として残り、
 * そのままAIへ送られる。原文は書き換えられないので、あとから直せない。
 * 職員が読み直して「確定」したものだけを原文にし、AIはその原文だけを
 * 材料にする（LlmActionController::transformVoice）。
 *
 * 記録の下部の「保存」でも原文は受け取らない。まとめて送ると、直している
 * 途中の文まで原文として残ってしまう。
 *
 * 【確定した原文は書き換えない】
 * 原文はAIが何を変えたのかを後から検証するための原本である。訂正したいときは
 * 新しい1件として確定する（RecordNote）。
 */
class RecordNoteController extends Controller
{
    public function store(StoreRecordNoteRequest $request, ServiceRecord $serviceRecord): RedirectResponse
    {
        Gate::authorize('update', $serviceRecord);

        $body = trim((string) $request->validated('body'));

        // 同じ内容が続けて届いたときだけ捨てる。確定を押し直しただけで同じ文が
        // 二重に積まれるのを防ぐためで、内容が違えば必ず別の1件として残す。
        // notes() は古い順に並べてあるので、並びを付け替えて最後の1件を取る。
        $last = $serviceRecord->notes()->reorder('id', 'desc')->first();

        if (trim((string) $last?->body) !== $body) {
            $serviceRecord->notes()->create([
                'recorded_by' => $request->user()?->id,
                'body' => $body,
                'input_method' => $request->enum('input_method', NoteInputMethod::class) ?? NoteInputMethod::Keyboard,
            ]);
        }

        return back()->with('success', '原文を確定しました。');
    }
}
