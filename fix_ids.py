import re

with open('src/components/Dashboard.js', 'r') as f:
    content = f.read()

# Replace cdnPurgeService select
content = content.replace(
    'value={ cdnPurgeService }\n\t\t\t\t\t\tonChange={ ( e ) =>\n\t\t\t\t\t\t\tsetCdnPurgeService( e.target.value )\n\t\t\t\t\t\t}\n\t\t\t\t\t>',
    'value={ cdnPurgeService }\n\t\t\t\t\t\tonChange={ ( e ) =>\n\t\t\t\t\t\t\tsetCdnPurgeService( e.target.value )\n\t\t\t\t\t\t}\n\t\t\t\t\t\taria-describedby="wppo-cdnPurgeService-desc"\n\t\t\t\t\t>'
)

# Replace cdnPurgeService desc
content = content.replace(
    '<p className="wppo-text-muted wppo-text-small">\n\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\'Purge the edge cache whenever the plugin cache is cleared.\',',
    '<p\n\t\t\t\t\t\tid="wppo-cdnPurgeService-desc"\n\t\t\t\t\t\tclassName="wppo-text-muted wppo-text-small"\n\t\t\t\t\t>\n\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\'Purge the edge cache whenever the plugin cache is cleared.\','
)

# Replace cloudflareZoneId input
content = content.replace(
    'onChange={ ( e ) =>\n\t\t\t\t\t\t\t\tsetCloudflareZoneId( e.target.value )\n\t\t\t\t\t\t\t}\n\t\t\t\t\t\t/>',
    'onChange={ ( e ) =>\n\t\t\t\t\t\t\t\tsetCloudflareZoneId( e.target.value )\n\t\t\t\t\t\t\t}\n\t\t\t\t\t\t\taria-describedby="wppo-cloudflareZoneId-desc"\n\t\t\t\t\t\t/>'
)

# Replace cloudflareZoneId desc
content = content.replace(
    '<p className="wppo-text-muted wppo-text-small">\n\t\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\t\'Define WPPO_CLOUDFLARE_API_TOKEN',
    '<p\n\t\t\t\t\t\t\tid="wppo-cloudflareZoneId-desc"\n\t\t\t\t\t\t\tclassName="wppo-text-muted wppo-text-small"\n\t\t\t\t\t\t>\n\t\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\t\'Define WPPO_CLOUDFLARE_API_TOKEN'
)

# Replace varnishPurgeUrls textarea
content = content.replace(
    'onChange={ ( e ) =>\n\t\t\t\t\t\t\t\tsetVarnishPurgeUrls( e.target.value )\n\t\t\t\t\t\t\t}\n\t\t\t\t\t\t\tplaceholder={ __(',
    'onChange={ ( e ) =>\n\t\t\t\t\t\t\t\tsetVarnishPurgeUrls( e.target.value )\n\t\t\t\t\t\t\t}\n\t\t\t\t\t\t\taria-describedby="wppo-varnishPurgeUrls-desc"\n\t\t\t\t\t\t\tplaceholder={ __('
)

# Replace varnishPurgeUrls desc
content = content.replace(
    '<p className="wppo-text-muted wppo-text-small">\n\t\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\t\'One URL per line. Each receives a PURGE request on cache clear.\',',
    '<p\n\t\t\t\t\t\t\tid="wppo-varnishPurgeUrls-desc"\n\t\t\t\t\t\t\tclassName="wppo-text-muted wppo-text-small"\n\t\t\t\t\t\t>\n\t\t\t\t\t\t\t{ __(\n\t\t\t\t\t\t\t\t\'One URL per line. Each receives a PURGE request on cache clear.\','
)

with open('src/components/Dashboard.js', 'w') as f:
    f.write(content)
