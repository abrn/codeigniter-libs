# codeigniter-libs
 some random libraries I've written over the years for codeigniter
 
### Currency (library)
fully functional library for grabbing exchange rates and converting between USD, GBP, EUR, Bitcoin and Monero - uses free API sources and math functionality safe to use in a commerce setup

### Captcha (library)
a captcha written from scratch using BD and imagemagick. I wrote this instead of using a service like recaptcha because it's highly customizable and can match your websites theme. you can use your own fonts, letter skew, coloring and transparent or image backgrounds

### Auth (model)
a database model that can be dropped in and out of any project to instantly add username and password auth, including options for **one time codes AND PGP key signing two-factor authentication** - requires an SQL server

### Sessions (library)
a boilerplate drop-in module for any project that can control user sessionss. lets you set/unset an authenticated status and has custom auth levels. it includes an option for a dark mode and display currency variable **(best used with the Currency library and Auth_model)**
