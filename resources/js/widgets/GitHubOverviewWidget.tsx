/**
 * Dashboard widget body: stars, open issues and open PRs per package
 * (roadmap 4.3), registered from `../boot.tsx` as
 * `ArtisanPackUIGitHubOverviewWidget`.
 */

import { formatNumber } from '../lib/format';
import type { GitHubOverviewData, WidgetProps } from '../lib/types';
import { AsOf, NoSnapshots } from './shared';

export default function GitHubOverviewWidget({ data }: WidgetProps<GitHubOverviewData>) {
    if (data.packages.length === 0) {
        return <NoSnapshots />;
    }

    return (
        <div className="space-y-2">
            <table className="w-full text-left text-sm">
                <caption className="sr-only">GitHub stars, open issues and open pull requests per package</caption>
                <thead>
                    <tr className="text-xs text-base-content/60">
                        <th scope="col" className="py-1 font-medium">Package</th>
                        <th scope="col" className="py-1 text-right font-medium">Stars</th>
                        <th scope="col" className="py-1 text-right font-medium">Issues</th>
                        <th scope="col" className="py-1 text-right font-medium">PRs</th>
                    </tr>
                </thead>
                <tbody>
                    {data.packages.map((row) => (
                        <tr key={row.id} className="border-t border-base-300/60">
                            <th scope="row" className="py-1 font-normal text-base-content">{row.title}</th>
                            <td className="py-1 text-right text-base-content">{formatNumber(row.stars)}</td>
                            <td className="py-1 text-right text-base-content">{formatNumber(row.openIssues)}</td>
                            <td className="py-1 text-right text-base-content">{formatNumber(row.openPullRequests)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t border-base-300 font-semibold">
                        <th scope="row" className="py-1 text-base-content">Total</th>
                        <td className="py-1 text-right text-base-content">{formatNumber(data.totals.stars)}</td>
                        <td className="py-1 text-right text-base-content">{formatNumber(data.totals.openIssues)}</td>
                        <td className="py-1 text-right text-base-content">{formatNumber(data.totals.openPullRequests)}</td>
                    </tr>
                </tfoot>
            </table>
            <AsOf date={data.asOf} />
        </div>
    );
}
