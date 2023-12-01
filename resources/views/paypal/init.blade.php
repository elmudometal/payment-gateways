<link rel="stylesheet" type="text/css" href="https://www.paypalobjects.com/webstatic/en_US/developer/docs/css/cardfields.css"/>
<section>
    <div class="row p-5">
        <div class="mx-auto col-md-8">
            <h1 class="text-ok">Pago en Dolares <img src="https://www.paypalobjects.com/webstatic/i/logo/rebrand/ppcom.svg" border="0" alt="Payments by PayPal"></h1>
            <h2>DATOS DE PAGO:</h2>
            <p>Número de orden: {{ $payment->id }}</p>
            <p>{{ config('paypal.commerce_name') }}</p>
            <p>Monto: {{ $payment->amount }}$ USD</p>
            <div id="paypal-button-container"></div>
            <div id="paypal-button-container" class="paypal-button-container"></div>
            <script src="https://www.paypal.com/sdk/js?components=buttons&client-id={{ $clientId }}"></script>

            <script>
                async function createOrderCallback() {
                    try {
                        const response = await fetch('{{ route('paypal.create', $payment->uuid) }}', {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                            },
                        });

                        const orderData = await response.json();

                        if (orderData.id) {
                            return orderData.id;
                        } else {
                            const errorDetail = orderData?.details?.[0];
                            const errorMessage = errorDetail
                                ? `${errorDetail.issue} ${errorDetail.description} (${orderData.debug_id})`
                                : JSON.stringify(orderData);

                            throw new Error(errorMessage);
                        }
                    } catch (error) {
                        console.error(error);
                        resultMessage(`Could not initiate PayPal Checkout...<br><br>${error}`);
                    }
                }

                async function onApproveCallback(data, actions) {
                    try {
                        const response = await fetch(`/paypal/commit/{{ $payment->uuid }}/${data.orderID}`, {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                            },
                        });

                        const orderData = await response.json();
                        // Three cases to handle:
                        //   (1) Recoverable INSTRUMENT_DECLINED -> call actions.restart()
                        //   (2) Other non-recoverable errors -> Show a failure message
                        //   (3) Successful transaction -> Show confirmation or thank you message

                        const transaction =
                            orderData?.purchase_units?.[0]?.payments?.captures?.[0] ||
                            orderData?.purchase_units?.[0]?.payments?.authorizations?.[0];
                        const errorDetail = orderData?.details?.[0];

                        // this actions.restart() behavior only applies to the Buttons component
                        if (errorDetail?.issue === "INSTRUMENT_DECLINED" && !data.card && actions) {
                            // (1) Recoverable INSTRUMENT_DECLINED -> call actions.restart()
                            // recoverable state, per https://developer.paypal.com/docs/checkout/standard/customize/handle-funding-failures/
                            return actions.restart();
                        } else if (
                            errorDetail ||
                            !transaction ||
                            transaction.status === "DECLINED"
                        ) {
                            // (2) Other non-recoverable errors -> Show a failure message
                            let errorMessage;
                            if (transaction) {
                                errorMessage = `Transaction ${transaction.status}: ${transaction.id}`;
                            } else if (errorDetail) {
                                errorMessage = `${errorDetail.description} (${orderData.debug_id})`;
                            } else {
                                errorMessage = JSON.stringify(orderData);
                            }

                            throw new Error(errorMessage);
                        } else {
                            // (3) Successful transaction -> Show confirmation or thank you message
                            // Or go to another URL:  actions.redirect('thank_you.html');
                            resultMessage(
                                `Transaction ${transaction.status}: ${transaction.id}<br><br>See console for all available details`,
                            );
                            console.log(
                                "Capture result",
                                orderData,
                                JSON.stringify(orderData, null, 2),
                            );
                            actions.redirect('{{ route('paypal.successful', $payment->uuid) }}');
                        }
                    } catch (error) {
                        console.error(error);
                        resultMessage(
                            `Sorry, your transaction could not be processed...<br><br>${error}`,
                        );
                    }
                }

                window.paypal
                    .Buttons({
                        createOrder: createOrderCallback,
                        onApprove: onApproveCallback,
                    })
                    .render("#paypal-button-container");

                const cardField = window.paypal.CardFields({
                    createOrder: createOrderCallback,
                    onApprove: onApproveCallback,
                });

                // Render each field after checking for eligibility
                if (cardField.isEligible()) {
                    const nameField = cardField.NameField();
                    nameField.render("#card-name-field-container");

                    const numberField = cardField.NumberField();
                    numberField.render("#card-number-field-container");

                    const cvvField = cardField.CVVField();
                    cvvField.render("#card-cvv-field-container");

                    const expiryField = cardField.ExpiryField();
                    expiryField.render("#card-expiry-field-container");

                    // Add click listener to submit button and call the submit function on the CardField component
                    document
                        .getElementById("multi-card-field-button")
                        .addEventListener("click", () => {
                            cardField.submit().catch((error) => {
                                resultMessage(
                                    `Sorry, your transaction could not be processed...<br><br>${error}`,
                                );
                            });
                        });
                } else {
                    // Hides card fields if the merchant isn't eligible
                    document.querySelector("#card-form").style = "display: none";
                }

                // Example function to show a result to the user. Your site's UI library can be used instead.
                function resultMessage(message) {
                    // const container = document.querySelector("#result-message");
                    // container.innerHTML = message;
                    console.log(message);
                }
            </script>
        </div>
    </div>
</section>
