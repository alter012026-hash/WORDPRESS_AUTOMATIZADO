param(
    [Parameter(Mandatory = $true)]
    [string] $SiteUrl
)

$cronUrl = $SiteUrl.TrimEnd('/') + '/wp-cron.php?doing_wp_cron'
$response = Invoke-WebRequest -UseBasicParsing -Uri $cronUrl -TimeoutSec 45
if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 300) {
    throw "WP-Cron respondeu HTTP $($response.StatusCode) em $cronUrl"
}
