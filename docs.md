
client side paywall
- user gets token from extension
- website uses my token to check if user has access to website
- if yes paywall can go down via dom manipulation

---

server side paywall
- user gets token from extension
- website uses my token to check if user has access to website
- if can just give global variable to allow website to fetch from server side.
- There is an issue because user of website can manipulate dom to change global variable to successfully get content from server and bypass paywall

**1. Backend Requirements**
- Add authorization header check to existing article routes
- If header present: verify with our API
- Return full/preview content based on verification

**2. API Verification Endpoint**
```
POST https://your-api.com/verify
Body: {
  url: current article url,
  token: from authorization header
}
Returns: { verified: boolean }
```

**3. Frontend Requirements**
- Include our script
- Script handles:
  - Getting token from extension
  - Adding auth header to article requests
  - Displaying full/preview content

Is this the level of detail you're looking for? We could:
1. Add more specifics about the API contract
2. Detail error scenarios
3. Specify exact header format

---
