import { Form, Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';
import StaffController from '@/actions/App/Http/Controllers/StaffController';
import { PageHeader } from '@/components/care/page-header';
import { Section } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PasswordInput from '@/components/password-input';
import { dashboard } from '@/routes';
import staffRoutes from '@/routes/staff';

type StaffForm = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
};

type Props = {
    /** null なら新規登録。 */
    staff: StaffForm | null;
    roles: { value: string; label: string; description: string }[];
};

/**
 * 職員アカウントの登録・編集（管理者のみ）。
 *
 * 【パスワードを管理者が決める形にしている】
 * 本来は招待メールから本人が設定するのが正しい。初期パスワードを口頭で
 * 伝える運用は、そのまま使い続けられてしまう。
 *
 * このアプリにはメール送信の手配がないため、暫定的にこの形にしている。
 * 実運用へ移す際は招待方式へ差し替える必要がある。
 */
export default function StaffForm({ staff, roles }: Props) {
    const isNew = staff === null;
    const [role, setRole] = useState(staff?.role ?? 'staff');

    return (
        <>
            <Head title={isNew ? '職員の追加' : `${staff.name} さんの編集`} />

            <Form
                {...(isNew
                    ? StaffController.store.form()
                    : StaffController.update.form(staff.id))}
                className="flex flex-col gap-4 p-4"
            >
                {({ processing, errors }) => (
                    <>
                        <PageHeader
                            title={
                                isNew
                                    ? '職員の追加'
                                    : `${staff.name} さんの編集`
                            }
                        />

                        <Section title="基本情報">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">お名前 *</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        defaultValue={staff?.name ?? ''}
                                        placeholder="山口 みどり"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">
                                        メールアドレス *
                                    </Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                        defaultValue={staff?.email ?? ''}
                                    />
                                    {/* 同じメールで2つのアカウントがあると、
                                        記録の書き手を追えなくなる */}
                                    <p className="text-muted-foreground text-xs">
                                        ログインに使います。記録の書き手を一意に辿るため、
                                        他の職員と同じものは使えません。
                                    </p>
                                    <InputError message={errors.email} />
                                </div>
                            </div>
                        </Section>

                        <Section
                            title="役割"
                            description="できることの範囲が変わります。あとから変更できます。"
                        >
                            <div className="grid gap-2">
                                {roles.map((option) => (
                                    <label
                                        key={option.value}
                                        className="hover:bg-accent/40 has-[:checked]:border-primary/50 has-[:checked]:bg-accent/40 flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                                    >
                                        <input
                                            type="radio"
                                            name="role"
                                            value={option.value}
                                            checked={role === option.value}
                                            onChange={() =>
                                                setRole(option.value)
                                            }
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                {option.label}
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {option.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError
                                message={errors.role}
                                className="mt-2"
                            />
                        </Section>

                        <Section
                            title="パスワード"
                            description={
                                isNew
                                    ? '本人にお伝えください。ログイン後、設定画面からご自身で変更していただけます。'
                                    : '変更する場合のみ入力してください。空欄なら現在のパスワードのままです。'
                            }
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        パスワード {isNew && '*'}
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required={isNew}
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        パスワード（確認） {isNew && '*'}
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required={isNew}
                                        autoComplete="new-password"
                                    />
                                </div>
                            </div>

                            <p className="text-muted-foreground mt-3 flex items-start gap-1.5 text-xs">
                                <Info
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden
                                />
                                本来は招待メールから本人が設定するのが望ましい方式です。
                                このデモではメール送信を用意していないため、管理者が設定しています。
                            </p>
                        </Section>

                        {!isNew && (
                            <Section
                                title="在籍"
                                description="退職された方もアカウントは残します。削除すると、その職員が書いた記録の記録者が辿れなくなるためです。"
                            >
                                <label className="flex min-h-11 items-center gap-3 text-sm">
                                    <Checkbox
                                        name="is_active"
                                        value="1"
                                        defaultChecked={staff.is_active}
                                    />
                                    在籍中
                                </label>
                            </Section>
                        )}

                        <div className="bg-background/95 sticky bottom-0 flex flex-wrap items-center justify-end gap-3 rounded-lg border px-4 py-3 backdrop-blur">
                            <Button type="submit" pending={processing}>
                                {processing
                                    ? '保存しています…'
                                    : isNew
                                      ? '追加する'
                                      : '保存する'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

StaffForm.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '職員アカウント', href: staffRoutes.index() },
    ],
};
