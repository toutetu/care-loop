/**
 * スクリプトから headless ブラウザを起動する共通処理。
 *
 * 探す順番:
 *   1. 環境変数 BROWSER_PATH
 *   2. puppeteer のキャッシュ（~/.cache/puppeteer）にある Chrome for Testing
 *   3. OS に入っている Chrome / Edge
 *
 * 2 を先にしているのは、Windows の Edge が headless で起動せずに終了する
 * ことがあり（2026-09 時点で確認）、Chrome for Testing のほうが確実なため。
 * 入れ方は docs/05_デザインガイド.md を参照。
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { getInstalledBrowsers } from '@puppeteer/browsers';
import puppeteer from 'puppeteer-core';

const CACHE_DIR = path.join(os.homedir(), '.cache', 'puppeteer');

const SYSTEM_CANDIDATES = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
];

async function findExecutable() {
    if (process.env.BROWSER_PATH) {
        return process.env.BROWSER_PATH;
    }

    if (fs.existsSync(CACHE_DIR)) {
        const installed = await getInstalledBrowsers({ cacheDir: CACHE_DIR });
        const chrome = installed.find((b) => b.browser === 'chrome');
        if (chrome) {
            return chrome.executablePath;
        }
    }

    return SYSTEM_CANDIDATES.find((candidate) => fs.existsSync(candidate));
}

export async function launchBrowser() {
    const executablePath = await findExecutable();

    if (!executablePath) {
        throw new Error(
            'ブラウザが見つかりません。次のどちらかを行ってください。\n' +
                '  npx @puppeteer/browsers install chrome@stable --path ~/.cache/puppeteer\n' +
                '  または BROWSER_PATH=<chrome.exe のパス> を指定',
        );
    }

    return puppeteer.launch({
        executablePath,
        headless: true,
        args: [
            '--no-first-run',
            '--no-default-browser-check',
            '--hide-scrollbars',
        ],
    });
}
