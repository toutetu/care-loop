/**
 * 外部リンク。
 *
 * サイドバーとトップページの両方から参照する。散らして書くと、
 * リポジトリ名を変えたときに片方だけ古いURLが残る。
 */

export const REPOSITORY_URL = 'https://github.com/toutetu/care-loop';

/**
 * docs/ 配下の文書を GitHub 上で開く URL。
 * ファイル名に日本語が含まれるため、エンコードして組み立てる。
 */
export function docUrl(fileName: string): string {
    return `${REPOSITORY_URL}/blob/main/docs/${encodeURIComponent(fileName)}`;
}

export const REQUIREMENTS_URL = docUrl('01_要件定義書.md');
export const DEPLOY_GUIDE_URL = docUrl('02_デプロイ手順.md');
export const SCALE_POLICY_URL = docUrl('03_スケール対応方針.md');
export const DATABASE_DESIGN_URL = docUrl('04_データベース設計.md');
export const DESIGN_GUIDE_URL = docUrl('05_デザインガイド.md');
