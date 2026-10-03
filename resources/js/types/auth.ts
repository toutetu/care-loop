/**
 * 権限ロール。PHP の App\Enums\UserRole と同じ値を持つ。
 *
 * 画面がこれを見てよいのは、見た目を変えるところまで（サイドバーに項目を
 * 出すか、役割バッジに何と書くか）。触らせないことの担保はサーバー側の
 * ポリシーとコントローラが持っており、ここで隠すのはその補助でしかない。
 */
export type UserRole = 'staff' | 'manager' | 'admin';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    /** 認証前の画面では auth.user ごと無いため、任意にしてある。 */
    role?: UserRole;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
