/**
 * 外部リンク。
 *
 * サイドバーとトップページの両方から参照する。散らして書くと、
 * リポジトリ名を変えたときに片方だけ古いURLが残る。
 */

export const REPOSITORY_URL = 'https://github.com/toutetu/care-loop';

/** 要件定義書。URLに日本語が含まれるため、エンコードした形で持つ。 */
export const REQUIREMENTS_URL = `${REPOSITORY_URL}/blob/main/docs/01_%E8%A6%81%E4%BB%B6%E5%AE%9A%E7%BE%A9%E6%9B%B8.md`;
