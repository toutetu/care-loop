import { Form, Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';
import ResidentController from '@/actions/App/Http/Controllers/ResidentController';
import { PageHeader } from '@/components/care/page-header';
import { Section } from '@/components/care/section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import residents from '@/routes/residents';

type ResidentForm = {
    id: number;
    name: string;
    name_kana: string;
    care_level_id: number | null;
    birth_date: string | null;
    gender: string | null;
    insurance_number: string | null;
    address: string | null;
    phone: string | null;
    family_contact: string | null;
    medical_history: string | null;
    care_manager_name: string | null;
    started_at: string | null;
    ended_at: string | null;
    service_weekdays: number[];
};

type Props = {
    /** null なら新規登録。 */
    resident: ResidentForm | null;
    careLevels: { id: number; name: string }[];
};

/** ISO-8601（月曜=1）。Carbon の dayOfWeekIso と合わせている。 */
const WEEKDAYS = [
    { value: 1, label: '月' },
    { value: 2, label: '火' },
    { value: 3, label: '水' },
    { value: 4, label: '木' },
    { value: 5, label: '金' },
    { value: 6, label: '土' },
];

/**
 * ご利用者の登録・編集。
 *
 * 【必須をお名前とカナだけにしている理由】
 * 契約の場でその日に分かることと、後日そろうことがある。保険者番号や
 * 既往歴がそろうまで登録できないと、その日の記録が残せない。
 * 記録を残せることを優先し、不足は後から埋められるようにする。
 */
export default function ResidentForm({ resident, careLevels }: Props) {
    const isNew = resident === null;

    const [careLevelId, setCareLevelId] = useState(
        resident?.care_level_id != null ? String(resident.care_level_id) : '',
    );
    const [gender, setGender] = useState(resident?.gender ?? '');
    const [weekdays, setWeekdays] = useState<number[]>(
        resident?.service_weekdays ?? [],
    );

    const toggleWeekday = (value: number) =>
        setWeekdays((current) =>
            current.includes(value)
                ? current.filter((day) => day !== value)
                : [...current, value].sort((a, b) => a - b),
        );

    return (
        <>
            <Head
                title={isNew ? 'ご利用者の登録' : `${resident.name} 様の編集`}
            />

            <Form
                {...(isNew
                    ? ResidentController.store.form()
                    : ResidentController.update.form(resident.id))}
                className="flex flex-col gap-4 p-4"
            >
                {({ processing, errors }) => (
                    <>
                        <PageHeader
                            title={
                                isNew
                                    ? 'ご利用者の登録'
                                    : `${resident.name} 様の編集`
                            }
                        />

                        <Section
                            title="基本情報"
                            description="お名前とカナだけ必須です。ほかは分かった時点で追加できます。"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">お名前 *</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        autoFocus
                                        defaultValue={resident?.name ?? ''}
                                        placeholder="佐藤 ハナ"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="name_kana">
                                        お名前（カナ） *
                                    </Label>
                                    <Input
                                        id="name_kana"
                                        name="name_kana"
                                        required
                                        defaultValue={resident?.name_kana ?? ''}
                                        placeholder="サトウ ハナ"
                                    />
                                    {/* 氏名は暗号化して保存するためSQLでは検索できない。
                                        カナの索引だけが後から探す手がかりになる。 */}
                                    <p className="text-muted-foreground text-xs">
                                        全角カタカナで入力してください。
                                        お名前は暗号化して保存するため、検索はこのカナを使います。
                                    </p>
                                    <InputError message={errors.name_kana} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="care_level_id">
                                        要介護度
                                    </Label>
                                    <Select
                                        value={careLevelId}
                                        onValueChange={setCareLevelId}
                                    >
                                        <SelectTrigger id="care_level_id">
                                            <SelectValue placeholder="選択してください" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {careLevels.map((level) => (
                                                <SelectItem
                                                    key={level.id}
                                                    value={String(level.id)}
                                                >
                                                    {level.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="care_level_id"
                                        value={careLevelId}
                                    />
                                    <InputError
                                        message={errors.care_level_id}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="gender">性別</Label>
                                    <Select
                                        value={gender}
                                        onValueChange={setGender}
                                    >
                                        <SelectTrigger id="gender">
                                            <SelectValue placeholder="選択してください" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="female">
                                                女性
                                            </SelectItem>
                                            <SelectItem value="male">
                                                男性
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <input
                                        type="hidden"
                                        name="gender"
                                        value={gender}
                                    />
                                    <InputError message={errors.gender} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="birth_date">生年月日</Label>
                                    <Input
                                        id="birth_date"
                                        name="birth_date"
                                        type="date"
                                        defaultValue={
                                            resident?.birth_date ?? ''
                                        }
                                    />
                                    {/* 年齢での絞り込みに使うため、生年月日だけは平文で持つ */}
                                    <InputError message={errors.birth_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="insurance_number">
                                        被保険者番号
                                    </Label>
                                    <Input
                                        id="insurance_number"
                                        name="insurance_number"
                                        defaultValue={
                                            resident?.insurance_number ?? ''
                                        }
                                        inputMode="numeric"
                                    />
                                    <InputError
                                        message={errors.insurance_number}
                                    />
                                </div>
                            </div>
                        </Section>

                        <Section
                            title="ご利用の予定"
                            description="利用曜日から次回のご利用日を算出します。別項目では管理しません。"
                        >
                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label>利用曜日</Label>
                                    <div className="flex flex-wrap gap-3">
                                        {WEEKDAYS.map((day) => (
                                            <label
                                                key={day.value}
                                                className="flex min-h-11 items-center gap-3 text-sm"
                                            >
                                                <Checkbox
                                                    checked={weekdays.includes(
                                                        day.value,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggleWeekday(day.value)
                                                    }
                                                />
                                                {day.label}
                                            </label>
                                        ))}
                                    </div>
                                    {weekdays.map((day) => (
                                        <input
                                            key={day}
                                            type="hidden"
                                            name="service_weekdays[]"
                                            value={day}
                                        />
                                    ))}
                                    <InputError
                                        message={errors.service_weekdays}
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="started_at">
                                            利用開始日
                                        </Label>
                                        <Input
                                            id="started_at"
                                            name="started_at"
                                            type="date"
                                            defaultValue={
                                                resident?.started_at ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.started_at}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="ended_at">
                                            利用終了日
                                        </Label>
                                        <Input
                                            id="ended_at"
                                            name="ended_at"
                                            type="date"
                                            defaultValue={
                                                resident?.ended_at ?? ''
                                            }
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            ご利用が続いている間は空欄のままにしてください。
                                        </p>
                                        <InputError message={errors.ended_at} />
                                    </div>
                                </div>
                            </div>
                        </Section>

                        <Section
                            title="連絡先と健康状態"
                            description="AIへ送る際は、お名前や連絡先を伏せ字へ置き換えます。既往歴は判断に必要なため伏せずに送ります。"
                        >
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">電話番号</Label>
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={resident?.phone ?? ''}
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="family_contact">
                                        ご家族の連絡先
                                    </Label>
                                    <Input
                                        id="family_contact"
                                        name="family_contact"
                                        defaultValue={
                                            resident?.family_contact ?? ''
                                        }
                                        placeholder="佐藤 一郎（長男）"
                                    />
                                    <InputError
                                        message={errors.family_contact}
                                    />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="address">ご住所</Label>
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={resident?.address ?? ''}
                                    />
                                    <InputError message={errors.address} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="care_manager_name">
                                        担当の介護支援専門員
                                    </Label>
                                    <Input
                                        id="care_manager_name"
                                        name="care_manager_name"
                                        defaultValue={
                                            resident?.care_manager_name ?? ''
                                        }
                                    />
                                    <InputError
                                        message={errors.care_manager_name}
                                    />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="medical_history">
                                        既往歴
                                    </Label>
                                    <textarea
                                        id="medical_history"
                                        name="medical_history"
                                        rows={3}
                                        defaultValue={
                                            resident?.medical_history ?? ''
                                        }
                                        placeholder="変形性膝関節症・高血圧"
                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-2"
                                    />
                                    {/* 嚥下障害の既往があるかどうかで、むせ込み1回の重みが変わる。
                                        氏名を伏せたうえで健康状態を送ることがこの設計の前提。 */}
                                    <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                        <Info
                                            className="mt-0.5 size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        リスク判定の材料になります。嚥下障害の有無などは、
                                        むせ込みの記録をどう受け止めるかに影響します。
                                    </p>
                                    <InputError
                                        message={errors.medical_history}
                                    />
                                </div>
                            </div>
                        </Section>

                        <div className="bg-background/95 sticky bottom-0 flex flex-wrap items-center justify-end gap-3 rounded-lg border px-4 py-3 backdrop-blur">
                            <Button type="submit" pending={processing}>
                                {processing
                                    ? '保存しています…'
                                    : isNew
                                      ? '登録する'
                                      : '保存する'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

ResidentForm.layout = {
    breadcrumbs: [
        { title: 'ダッシュボード', href: dashboard() },
        { title: '利用者一覧', href: residents.index() },
    ],
};
