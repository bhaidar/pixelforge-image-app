/** Format integer nanodollars as dollars without floating point, e.g. 46125000 -> "$0.046125". */
export function formatNanos(nanos: number | null | undefined): string {
    if (nanos === null || nanos === undefined) {
        return '-';
    }

    const negative = nanos < 0;
    const abs = BigInt(Math.abs(nanos));
    const whole = abs / 1_000_000_000n;
    const fraction = (abs % 1_000_000_000n).toString().padStart(9, '0').replace(/0+$/, '').padEnd(2, '0');

    return `${negative ? '-' : ''}$${whole}.${fraction}`;
}
