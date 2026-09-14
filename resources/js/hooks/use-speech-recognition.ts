import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * ブラウザの音声認識（要件定義 7.8節 ②）。
 *
 * 【これは補助的な入力手段である】
 * 主たる入力方法は、OS標準キーボードの音声入力（同 ①）である。
 * 職員が日常的に使い慣れている操作で、アプリ側の実装もいらない。
 *
 * このAPIが使えるブラウザでだけ、アプリ内のマイクボタンを出す。
 * 使えない環境ではボタンを出さない。押しても動かないボタンは、
 * 現場では「アプリが壊れている」と受け取られる。
 *
 * 【音声をサーバーへ送らない】
 * 録音してサーバーへ送る方式は採らない。音声そのものが要配慮個人情報であり、
 * 保管・暗号化・削除の責任が増えるわりに、得られる精度が見合わない
 * （要件定義 7.8節 ③を不採用とした理由）。
 * ここで扱うのは認識後の文字列だけである。
 */

type SpeechRecognitionAlternative = { transcript: string };

type SpeechRecognitionResult = {
    isFinal: boolean;
    0: SpeechRecognitionAlternative;
};

type SpeechRecognitionEventLike = {
    resultIndex: number;
    results: { length: number; [index: number]: SpeechRecognitionResult };
};

type SpeechRecognitionLike = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start: () => void;
    stop: () => void;
    onresult: ((event: SpeechRecognitionEventLike) => void) | null;
    onerror: (() => void) | null;
    onend: (() => void) | null;
};

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike;

function getConstructor(): SpeechRecognitionConstructor | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const candidate = window as unknown as {
        SpeechRecognition?: SpeechRecognitionConstructor;
        webkitSpeechRecognition?: SpeechRecognitionConstructor;
    };

    return candidate.SpeechRecognition ?? candidate.webkitSpeechRecognition ?? null;
}

export function useSpeechRecognition(onTranscript: (text: string) => void) {
    const [supported, setSupported] = useState(false);
    const [listening, setListening] = useState(false);
    const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
    const callbackRef = useRef(onTranscript);

    callbackRef.current = onTranscript;

    useEffect(() => {
        setSupported(getConstructor() !== null);
    }, []);

    const stop = useCallback(() => {
        recognitionRef.current?.stop();
        recognitionRef.current = null;
        setListening(false);
    }, []);

    const start = useCallback(() => {
        const Constructor = getConstructor();

        if (Constructor === null) {
            return;
        }

        const recognition = new Constructor();

        recognition.lang = 'ja-JP';
        // 介護記録は一文で終わらない。区切るたびに押し直すのでは使えない。
        recognition.continuous = true;
        recognition.interimResults = false;

        recognition.onresult = (event) => {
            let text = '';

            for (let index = event.resultIndex; index < event.results.length; index += 1) {
                const result = event.results[index];

                if (result.isFinal) {
                    text += result[0].transcript;
                }
            }

            if (text !== '') {
                callbackRef.current(text);
            }
        };

        recognition.onerror = () => {
            setListening(false);
            recognitionRef.current = null;
        };

        recognition.onend = () => {
            setListening(false);
            recognitionRef.current = null;
        };

        recognition.start();
        recognitionRef.current = recognition;
        setListening(true);
    }, []);

    // 画面を離れたらマイクを止める。切り忘れは録られ続けることになる。
    useEffect(() => () => recognitionRef.current?.stop(), []);

    return { supported, listening, start, stop };
}
